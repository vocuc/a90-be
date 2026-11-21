<?php

namespace App\Services\Channel;

use App\Enums\SocialPlatform;
use App\Enums\SocialPostStatus;
use App\Exceptions\BusinessException;
use App\Models\Channel;
use App\Models\ChannelLineup;
use App\Models\ChannelTextTemplate;
use App\Models\ContentPlan;
use App\Models\ContentPlanItem;
use App\Models\Generation;
use App\Models\GenerationOutput;
use App\Models\Hashtag;
use App\Models\ImageBatch;
use App\Models\Product;
use App\Models\SocialPost;
use App\Models\SocialPostImage;
use App\Models\VideoTemplate;
use App\Models\Worker;
use App\Services\AuditLogger;
use App\Services\MediaStorage;
use App\Services\OutfitBatchService;
use App\Services\Social\VideoPostService;
use App\Support\PostSamples;
use App\Support\VietnamHolidays;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Kế hoạch nội dung của kênh (a90-fe note/xay-kenh-tiktok/dac-ta-giai-doan-1.txt mục 5.4 - 5.10).
 * Lệnh /lap-ke-hoach (skill FlowKit) lấy context (backend tính sẵn slot giờ, giữ trong kỳ preparing), soạn bài rồi gửi
 * lên; backend kiểm tra từng bài (5.8), lưu kỳ draft. Chủ shop duyệt (hoặc kênh tự duyệt) -> sinh bài đăng; bài ảnh có
 * chữ chờ worker chèn chữ rồi mới vào hàng chờ đăng.
 */
class ChannelPlanService
{
    /** Số ảnh tối đa gửi trong context. */
    public const CONTEXT_IMAGES = 400;

    /** Số bài gần nhất gửi kèm để tránh trùng. */
    public const RECENT_POSTS = 60;

    /** Khoảng cách tối thiểu giữa 2 bài trong cùng khung giờ (phút). */
    public const MIN_GAP_MINUTES = 20;

    /** Không lập slot quá gần hiện tại (phút). */
    public const LEAD_MINUTES = 30;

    /** Tỉ lệ thực tế lệch tuyến quá số điểm % này thì cảnh báo. */
    public const LINEUP_TOLERANCE = 15;

    public function __construct(
        private MediaStorage $media,
        private AuditLogger $audit,
        private ContentJobService $contentJobs,
        private OutfitBatchService $outfitBatches,
    ) {}

    // ------------------------------------------------------------------ Context cho lệnh /lap-ke-hoach

    /**
     * Toàn bộ dữ liệu lệnh cần (mục 6.2). Kênh đủ điều kiện và chưa có kỳ draft: tạo / dùng lại kỳ preparing giữ slot.
     */
    public function context(Channel $channel): array
    {
        $channel->loadMissing('worker', 'user');
        $missing = $channel->missing();
        $draft = $channel->plans()->where('status', ContentPlan::DRAFT)->latest('id')->first();
        $plan = null;
        if (! $draft && ! array_intersect($missing, ['worker', 'time_slots', 'lineup', 'text_templates'])) {
            $plan = $this->preparingPlan($channel);
        }
        $lineup = $channel->approvedLineup();
        // Chỉ bài viết mẫu ĐÃ LÀM XONG (PostSamples::READY); bài chưa bật trong tuyến cũ bị bỏ qua
        $usedKeys = array_values(array_filter($lineup?->sampleKeys() ?? array_keys(PostSamples::all()), [PostSamples::class, 'isEnabled']));
        $from = $plan ? CarbonImmutable::parse($plan->start_date) : CarbonImmutable::today();
        $to = $from->addDays(($plan?->days ?? $channel->plan_days) + 7);

        return [
            'channel' => [
                'id' => $channel->id,
                'name' => $channel->name,
                'shop' => $channel->user?->creatorProfile?->shop_name ?? $channel->user?->name,
                'worker' => $channel->worker ? ['id' => $channel->worker->id, 'name' => $channel->worker->name] : null,
                'plan_days' => $channel->plan_days,
                'posts_per_day' => $channel->posts_per_day,
                'time_slots' => $channel->time_slots ?? [],
                'video_percent' => $channel->video_percent,
                'auto_approve' => [
                    'lineup' => $channel->auto_approve_lineup,
                    'templates' => $channel->auto_approve_templates,
                    'plan' => $channel->auto_approve_plan,
                ],
                'ai' => [...$channel->aiSettings(), 'hashtags_max' => (int) config('ai90.social.ai_max_hashtags')],
                // Bài viết mẫu lấy ảnh bằng lô phối đồ (vd lookbook) cần worker phối đồ được gắn cho shop
                'outfit_worker' => Worker::query()->assignedTo($channel->user_id, Worker::TYPE_OUTFIT)->exists(),
                // Bài ảnh có chữ / bìa ghép cần content-worker của shop dựng (a90-fe note/xay-kenh-tiktok/worker-content.txt)
                'content_worker' => ContentJobService::hasWorker($channel->user_id),
            ],
            'missing' => $missing,
            'persona' => $channel->persona ?? (object) [],
            'lineup' => [
                'approved' => $lineup ? ['id' => $lineup->id,
                    'items' => array_values(array_filter($lineup->items, fn ($i) => PostSamples::isEnabled($i['sample_key']))),
                    // Bài trong tuyến nhưng CHƯA LÀM XONG: không lập kế hoạch cho các bài này
                    'disabled' => array_values(array_filter($lineup->sampleKeys(), fn ($k) => ! PostSamples::isEnabled($k)))] : null,
                'pending' => $channel->lineups()->where('status', ChannelLineup::PENDING)->latest('id')->get(['id', 'items', 'created_at']),
            ],
            'text_templates' => $channel->textTemplates()->whereIn('status', [ChannelTextTemplate::APPROVED, ChannelTextTemplate::PENDING])
                // sample_keys: loại bài viết mẫu được dùng template (rỗng = không dùng được)
                ->orderBy('id')->get(['id', 'name', 'status', 'style', 'sample_keys', 'sample_text']),
            'post_samples' => array_values(array_intersect_key(PostSamples::all(), array_flip($usedKeys))),
            'all_post_samples' => array_map(fn ($s) => ['key' => $s['key'], 'name' => $s['name'], 'group' => $s['group'], 'goal' => $s['goal'], 'formats' => $s['formats']], array_values(PostSamples::enabled())),
            'products' => Product::query()->where('user_id', $channel->user_id)->active()->with('hashtags')->orderByDesc('id')->limit(100)->get()
                ->map(fn (Product $p) => ['id' => $p->id, 'name' => $p->name, 'prompt_note' => $p->prompt_note, 'hashtags' => $p->hashtags->pluck('name')->all()]),
            'images' => $this->imageCatalog($channel),
            'video_templates' => VideoTemplate::query()->where('user_id', $channel->user_id)->usable()->orderBy('name')
                ->get(['id', 'name', 'image_count']),
            'recent_posts' => $this->recentPosts($channel),
            'holidays' => VietnamHolidays::between($from, $to),
            'plan' => $plan ? [
                'id' => $plan->id,
                'start_date' => $plan->start_date->toDateString(),
                'days' => $plan->days,
                'slots' => $plan->slots,
                'expires_at' => $plan->created_at->addHours(ContentPlan::SLOT_HOLD_HOURS),
            ] : null,
            'draft_plan_id' => $draft?->id,
            'redo_requests' => $this->redoRequests($channel),
            'now' => now()->toIso8601String(),
        ];
    }

    /** Kỳ preparing còn hạn của kênh, không có thì tạo mới với slot giờ backend tính. */
    private function preparingPlan(Channel $channel): ContentPlan
    {
        return DB::transaction(function () use ($channel) {
            Channel::query()->lockForUpdate()->find($channel->id);
            $existing = $channel->plans()->where('status', ContentPlan::PREPARING)->latest('id')->first();
            if ($existing && $existing->created_at->gt(now()->subHours(ContentPlan::SLOT_HOLD_HOURS))) {
                return $existing;
            }
            $existing?->update(['status' => ContentPlan::CANCELLED]);

            $start = $this->startDate($channel);

            return $channel->plans()->create([
                'start_date' => $start->toDateString(),
                'days' => $channel->plan_days,
                'status' => ContentPlan::PREPARING,
                'slots' => $this->computeSlots($channel, $start),
            ]);
        });
    }

    /** Ngày đầu kỳ: sau ngày cuối có bài tương lai của kênh; không có: hôm nay (còn khung giờ) hoặc ngày mai. */
    public function startDate(Channel $channel): CarbonImmutable
    {
        $last = $channel->posts()
            ->whereIn('status', [SocialPostStatus::Generating, SocialPostStatus::PendingReview, SocialPostStatus::Queued])
            ->where('scheduled_at', '>=', now())
            ->max('scheduled_at');
        if ($last) {
            return CarbonImmutable::parse($last)->addDay()->startOfDay();
        }
        $today = CarbonImmutable::today();
        $threshold = now()->addMinutes(self::LEAD_MINUTES)->format('H:i');
        $hasSlotToday = collect($channel->time_slots ?? [])->contains(fn ($s) => $s['end'] > $threshold);

        return $hasSlotToday ? $today : $today->addDay();
    }

    /**
     * Slot giờ của kỳ: mỗi ngày posts_per_day bài chia đều vào các khung giờ (dư thì khung đầu nhận thêm), mỗi bài giờ
     * ngẫu nhiên trong phần của nó trong khung (cách nhau >= MIN_GAP_MINUTES). Slot đã qua (hôm nay) bị bỏ.
     * Bài video = làm tròn(tổng x video_percent / 100), rải đều.
     *
     * @return array<int, array{index: int, at: string, format: string}>
     */
    public function computeSlots(Channel $channel, CarbonImmutable $start): array
    {
        $windows = collect($channel->time_slots ?? [])->sortBy('start')->values();
        if ($windows->isEmpty()) {
            return [];
        }
        $perWindow = array_fill(0, $windows->count(), intdiv($channel->posts_per_day, $windows->count()));
        for ($i = 0; $i < $channel->posts_per_day % $windows->count(); $i++) {
            $perWindow[$i]++;
        }

        $times = [];
        $earliest = now()->addMinutes(self::LEAD_MINUTES);
        for ($d = 0; $d < $channel->plan_days; $d++) {
            $day = $start->addDays($d);
            foreach ($windows as $w => $window) {
                $count = $perWindow[$w];
                if ($count === 0) {
                    continue;
                }
                $from = $day->setTimeFromTimeString($window['start']);
                $to = $day->setTimeFromTimeString($window['end']);
                $segment = intdiv(max(0, (int) $from->diffInMinutes($to)), $count);
                for ($k = 0; $k < $count; $k++) {
                    $segStart = $from->addMinutes($segment * $k);
                    $room = max(0, $segment - self::MIN_GAP_MINUTES);
                    $at = $segStart->addMinutes($room > 0 ? random_int(0, $room) : 0)->second(0);
                    if ($at->lt($earliest)) {
                        continue;
                    }
                    $times[] = $at;
                }
            }
        }

        $total = count($times);
        $videos = (int) round($total * $channel->video_percent / 100);
        $videoIdx = [];
        for ($v = 0; $v < $videos; $v++) {
            $videoIdx[(int) floor(($v + 0.5) * $total / $videos)] = true;
        }

        return collect($times)->values()->map(fn (CarbonImmutable $at, int $i) => [
            'index' => $i,
            'at' => $at->toIso8601String(),
            'format' => isset($videoIdx[$i]) ? ContentPlanItem::FORMAT_VIDEO : ContentPlanItem::FORMAT_PHOTO,
        ])->all();
    }

    /** Ảnh dùng được của shop (mới nhất trước), kèm loại, sản phẩm, số lần đã đăng ở kênh. */
    private function imageCatalog(Channel $channel): Collection
    {
        $uses = DB::table('social_post_images')
            ->join('social_posts', 'social_posts.id', '=', 'social_post_images.social_post_id')
            ->where('social_posts.channel_id', $channel->id)
            ->whereNotNull('social_post_images.output_id')
            ->groupBy('social_post_images.output_id')
            ->selectRaw('social_post_images.output_id, COUNT(*) AS total, MAX(social_posts.scheduled_at) AS last_at')
            ->get()
            ->keyBy('output_id');

        $outputs = GenerationOutput::query()
            ->usable()
            ->whereHas('generation', fn ($q) => $q->where('user_id', $channel->user_id))
            ->with(['generation.creative:id,title', 'generation.product:id,name'])
            ->orderByDesc('id')
            ->limit(self::CONTEXT_IMAGES)
            ->get();

        return $outputs->map(function (GenerationOutput $o) use ($uses) {
            $g = $o->generation;
            $use = $uses->get($o->id);

            return [
                'ref' => self::ref($o->generation_id, $o->id),
                'thumb_url' => $this->media->url($o->thumbPathOrOriginal()),
                'kind' => $g->isOutfit() ? 'outfit' : 'product',
                'product' => $g->product?->name,
                'product_id' => $g->product_id ?: null,
                'creative' => $g->creative?->title,
                'used_in_channel' => (int) ($use->total ?? 0),
                'last_used_at' => $use->last_at ?? null,
                'created_at' => $o->created_at?->toIso8601String(),
            ];
        })->values();
    }

    private function recentPosts(Channel $channel): Collection
    {
        return $channel->posts()->with('images')->orderByDesc('scheduled_at')->orderByDesc('id')->limit(self::RECENT_POSTS)->get()
            ->map(fn (SocialPost $p) => [
                'id' => $p->id,
                'scheduled_at' => $p->scheduled_at?->toIso8601String(),
                'sample_key' => $p->sample_key,
                'hook' => $p->hook,
                'title' => $p->title,
                'images' => $p->images->filter(fn ($i) => $i->output_id)->map(fn ($i) => self::ref($i->generation_id, $i->output_id))->values(),
            ]);
    }

    private function redoRequests(Channel $channel): Collection
    {
        return $channel->posts()->whereNotNull('redo_requested_at')
            ->whereIn('status', [SocialPostStatus::Generating, SocialPostStatus::PendingReview, SocialPostStatus::Queued])
            ->with('images')->orderBy('scheduled_at')->get()
            ->map(fn (SocialPost $p) => [
                'post_id' => $p->id,
                'scheduled_at' => $p->scheduled_at?->toIso8601String(),
                'format' => $p->type,
                'sample_key' => $p->sample_key,
                'hook' => $p->hook,
                'title' => $p->title,
                'images' => $p->images->map(fn ($i) => ['ref' => $i->output_id ? self::ref($i->generation_id, $i->output_id) : null, 'text' => $i->overlay_text])->values(),
            ]);
    }

    public static function ref(int $generationId, int $outputId): string
    {
        return "g{$generationId}-o{$outputId}";
    }

    // ------------------------------------------------------------------ Chân dung, tuyến nội dung, template chữ

    /** Lệnh lưu chân dung khách hàng: lưu thẳng, không cần duyệt (trả lời 12.2). */
    public function savePersona(Channel $channel, array $persona): Channel
    {
        $channel->update(['persona' => Channel::normalizePersona($persona)]);

        return $channel;
    }

    /**
     * Lưu tuyến nội dung: key phải có trong thư viện, bỏ trùng, tổng % chuẩn hoá về 100.
     * $fromCommand: lệnh /lap-ke-hoach gửi (theo công tắc tự duyệt); chủ shop sửa thì giữ trạng thái đang có.
     *
     * @param  array<int, array{sample_key: string, percent: numeric, reason?: ?string}>  $items
     */
    public function saveLineup(Channel $channel, array $items, bool $fromCommand, ?ChannelLineup $lineup = null): ChannelLineup
    {
        $clean = $this->normalizeLineupItems($items);
        if ($lineup) {
            $lineup->update(['items' => $clean]);

            return $lineup;
        }
        $approved = $fromCommand && $channel->auto_approve_lineup;

        return $channel->lineups()->create([
            'items' => $clean,
            'status' => $approved ? ChannelLineup::APPROVED : ChannelLineup::PENDING,
            'approved_at' => $approved ? now() : null,
        ]);
    }

    /** @return array<int, array{sample_key: string, percent: int, reason: ?string}> */
    public function normalizeLineupItems(array $items): array
    {
        $disabled = collect($items)->pluck('sample_key')->filter(fn ($k) => PostSamples::exists((string) $k) && ! PostSamples::isEnabled((string) $k))
            ->map(fn ($k) => PostSamples::get((string) $k)['name'])->unique()->values();
        if ($disabled->isNotEmpty()) {
            throw new BusinessException('SAMPLE_NOT_READY', 'Bài viết mẫu chưa làm xong, chưa dùng được: '.$disabled->implode(', ').'.', 422);
        }
        $rows = collect($items)
            ->filter(fn ($i) => isset($i['sample_key']) && PostSamples::exists((string) $i['sample_key']) && (float) ($i['percent'] ?? 0) > 0)
            ->unique('sample_key')
            ->values();
        if ($rows->isEmpty()) {
            throw new BusinessException('LINEUP_EMPTY', 'Tuyến nội dung cần ít nhất 1 bài viết mẫu hợp lệ có tỉ lệ > 0.', 422);
        }
        // Chuẩn hoá về tổng 100 (chia phần dư cho dòng có phần lẻ lớn nhất)
        $sum = $rows->sum(fn ($i) => (float) $i['percent']);
        $raw = $rows->map(fn ($i) => (float) $i['percent'] * 100 / $sum);
        $floors = $raw->map(fn ($v) => (int) floor($v));
        $remain = 100 - $floors->sum();
        $order = $raw->map(fn ($v, $k) => [$k, $v - floor($v)])->sortByDesc(1)->pluck(0)->take($remain)->all();
        foreach ($order as $k) {
            $floors[$k]++;
        }

        return $rows->map(fn ($i, $k) => [
            'sample_key' => (string) $i['sample_key'],
            'percent' => $floors[$k],
            'reason' => isset($i['reason']) ? Str::limit(trim((string) $i['reason']), 300, '') : null,
        ])->filter(fn ($i) => $i['percent'] > 0)->values()->all();
    }

    public function decideLineup(ChannelLineup $lineup, bool $approve): ChannelLineup
    {
        if ($lineup->status !== ChannelLineup::PENDING) {
            throw new BusinessException('LINEUP_DECIDED', 'Tuyến nội dung này đã được duyệt / từ chối.', 409);
        }
        $lineup->update($approve
            ? ['status' => ChannelLineup::APPROVED, 'approved_at' => now()]
            : ['status' => ChannelLineup::REJECTED]);

        return $lineup;
    }

    /**
     * Lệnh lưu template chữ (6 mẫu). Theo công tắc tự duyệt.
     *
     * @param  array<int, array{name: string, style: array, sample_keys?: ?array, sample_text?: ?string}>  $templates
     * @return Collection<int, ChannelTextTemplate>
     */
    public function saveTemplates(Channel $channel, array $templates): Collection
    {
        $status = $channel->auto_approve_templates ? ChannelTextTemplate::APPROVED : ChannelTextTemplate::PENDING;

        return DB::transaction(fn () => collect($templates)->map(fn ($t) => $channel->textTemplates()->create([
            'name' => Str::limit(trim((string) $t['name']), 100, ''),
            'status' => $status,
            'style' => ChannelTextTemplate::cleanStyle($t['style']),
            'sample_keys' => ChannelTextTemplate::cleanSampleKeys($t['sample_keys'] ?? []),
            'sample_text' => isset($t['sample_text']) ? Str::limit(trim((string) $t['sample_text']), 150, '') : null,
        ])));
    }

    // ------------------------------------------------------------------ Nhận bài kế hoạch

    /**
     * Lệnh gửi bài cho các slot của kỳ (lần đầu hoặc gửi bổ sung). Bài sai trả về rejected kèm lý do, không lưu.
     *
     * @return array{plan: ContentPlan, accepted: int[], rejected: array<int, array{slot_index: mixed, errors: string[]}>, warnings: string[]}
     */
    public function submitItems(Channel $channel, ContentPlan $plan, array $items): array
    {
        if (! in_array($plan->status, [ContentPlan::PREPARING, ContentPlan::DRAFT], true)) {
            throw new BusinessException('PLAN_CLOSED', 'Kỳ kế hoạch đã duyệt hoặc đã huỷ, không nhận thêm bài.', 409);
        }
        if ($plan->status === ContentPlan::PREPARING && $plan->created_at->lt(now()->subHours(ContentPlan::SLOT_HOLD_HOURS))) {
            throw new BusinessException('PLAN_EXPIRED', 'Slot giờ đã hết hạn giữ chỗ, chạy lại lệnh để lấy slot mới.', 409);
        }
        $lineup = $channel->approvedLineup()
            ?? throw new BusinessException('LINEUP_REQUIRED', 'Kênh chưa có tuyến nội dung đã duyệt.', 409);

        $accepted = [];
        $rejected = [];
        $taken = $plan->items()->pluck('slot_index')->all();
        $signatures = $this->existingSignatures($channel, $plan);

        foreach ($items as $raw) {
            $slotIndex = $raw['slot_index'] ?? null;
            $slot = is_numeric($slotIndex) ? $plan->slot((int) $slotIndex) : null;
            if (! $slot) {
                $rejected[] = ['slot_index' => $slotIndex, 'errors' => ['slot_index không có trong kỳ.']];

                continue;
            }
            if (in_array((int) $slotIndex, $taken, true)) {
                $rejected[] = ['slot_index' => $slotIndex, 'errors' => ['Slot này đã có bài.']];

                continue;
            }
            [$data, $errors] = $this->validateItem($channel, $lineup, $raw, $slot['format'], $signatures);
            if ($errors) {
                $rejected[] = ['slot_index' => $slotIndex, 'errors' => $errors];

                continue;
            }
            $signature = $data['_signature'];
            unset($data['_signature']);
            $plan->items()->create([
                ...$data,
                'slot_index' => (int) $slotIndex,
                'scheduled_at' => CarbonImmutable::parse($slot['at']),
                'status' => ContentPlanItem::DRAFT,
            ]);
            $taken[] = (int) $slotIndex;
            $signatures[] = $signature;
            $accepted[] = (int) $slotIndex;
        }

        $warnings = $this->lineupWarnings($plan->fresh(), $lineup);
        if ($accepted) {
            $plan->update(['status' => ContentPlan::DRAFT, 'warnings' => $warnings ?: null]);
        }
        $this->audit->log('content_plan.submit', $plan, ['accepted' => count($accepted), 'rejected' => count($rejected)]);

        if ($accepted && $channel->auto_approve_plan && $plan->status === ContentPlan::DRAFT) {
            $plan = $this->approve($plan);
        }

        return ['plan' => $plan->fresh(), 'accepted' => $accepted, 'rejected' => $rejected, 'warnings' => $warnings];
    }

    /**
     * Kiểm tra 1 bài (mục 5.8). $format do slot quyết định.
     *
     * @return array{0: array, 1: string[]} [dữ liệu đã chuẩn hoá để lưu, lỗi]
     */
    public function validateItem(Channel $channel, ChannelLineup $lineup, array $raw, string $format, array $signatures, ?int $ignorePostId = null): array
    {
        $errors = [];
        $sampleKey = (string) ($raw['sample_key'] ?? '');
        $sample = PostSamples::get($sampleKey);
        if (! $sample) {
            return [[], ["sample_key \"{$sampleKey}\" không có trong thư viện bài viết mẫu."]];
        }
        if (! $sample['enabled']) {
            return [[], ["Bài viết mẫu \"{$sample['name']}\" chưa làm xong, chưa dùng được."]];
        }
        if (! in_array($sampleKey, $lineup->sampleKeys(), true)) {
            $errors[] = "Bài viết mẫu \"{$sampleKey}\" không thuộc tuyến nội dung đã duyệt.";
        }
        if (isset($raw['format']) && $raw['format'] !== $format) {
            $errors[] = "Slot này là {$format}, bài gửi format {$raw['format']}.";
        }
        if (! in_array($format, $sample['formats'], true)) {
            $errors[] = "Bài viết mẫu \"{$sampleKey}\" không dùng cho {$format}.";
        }
        $hook = trim((string) ($raw['hook'] ?? ''));
        if ($hook === '' || mb_strlen($hook) > 200) {
            $errors[] = 'hook bắt buộc, tối đa 200 ký tự.';
        }

        // Bài viết mẫu lấy ảnh bằng LÔ PHỐI ĐỒ mới (vd lookbook): không chọn ảnh có sẵn, chỉ ghi số ảnh + sản phẩm
        if (($sample['image_source'] ?? PostSamples::SOURCE_LIBRARY) === PostSamples::SOURCE_OUTFIT_BATCH) {
            $outfit = $this->validateOutfit($channel, $sample, $raw, $format, $errors);
            // Hook in lên ảnh bìa theo template chữ của kênh (worker phối đồ v2, người dùng chốt 2026-11-21)
            $textTemplateId = $this->approvedTemplateId($channel, $raw, $sampleKey, $errors);
            $signature = self::signature($sampleKey, $hook, []);
            if (in_array($signature, $signatures, true)) {
                $errors[] = 'Trùng hook với bài lô phối đồ khác của kênh. Đổi hook.';
            }
            if ($errors) {
                return [[], $errors];
            }

            return [[
                'sample_key' => $sampleKey,
                'hook' => $hook,
                'format' => $format,
                'video_template_id' => null,
                'images' => [],
                'outfit' => $outfit,
                'text_template_id' => $textTemplateId,
                ...$this->caption($channel, $raw),
                '_signature' => $signature,
            ], []];
        }

        // Ảnh. Dạng có bìa ghép (top_n): ảnh gửi = slide #1..#N, content-worker tự thêm bìa (slide 1) khi dựng
        $collage = (bool) ($sample['cover_collage'] ?? false);
        $offset = $collage ? 1 : 0;
        $images = array_values(is_array($raw['images'] ?? null) ? $raw['images'] : []);
        $parsed = [];
        foreach ($images as $i => $img) {
            if (! preg_match('/^g(\d+)-o(\d+)$/', (string) ($img['ref'] ?? ''), $m)) {
                $errors[] = 'Ảnh #'.($i + 1).': ref không đúng dạng g{generation_id}-o{output_id}.';

                continue;
            }
            $parsed[] = ['generation_id' => (int) $m[1], 'output_id' => (int) $m[2], 'text' => trim((string) ($img['text'] ?? '')) ?: null];
        }
        $outputs = GenerationOutput::query()->usable()
            ->whereIn('id', array_column($parsed, 'output_id'))
            ->whereHas('generation', fn ($q) => $q->where('user_id', $channel->user_id))
            ->with('generation')
            ->get()
            ->keyBy('id');
        if (count(array_unique(array_column($parsed, 'output_id'))) !== count($parsed)) {
            $errors[] = 'Ảnh bị chọn trùng trong bài.';
        }

        $template = null;
        if ($format === ContentPlanItem::FORMAT_VIDEO) {
            $template = VideoTemplate::query()->where('user_id', $channel->user_id)->usable()->find((int) ($raw['video_template_id'] ?? 0));
            if (! $template) {
                $errors[] = 'Bài video cần video_template_id đang bật của shop.';
            } elseif (count($parsed) !== $template->image_count) {
                $errors[] = "Template \"{$template->name}\" cần đúng {$template->image_count} ảnh, bài có ".count($parsed).'.';
            }
        } else {
            $min = $sample['slides']['min'] - $offset;
            $max = min($sample['slides']['max'], SocialPost::MAX_IMAGES) - $offset;
            if (count($parsed) < $min || count($parsed) > $max) {
                $errors[] = "Bài viết mẫu \"{$sampleKey}\" cần {$min}-{$max} ảnh".($collage ? ' (không tính bìa ghép, worker tự thêm)' : '').', bài có '.count($parsed).'.';
            }
        }

        $groupKeys = [];
        $hasText = false;
        foreach ($parsed as $i => $p) {
            $output = $outputs->get($p['output_id']);
            if (! $output || $output->generation_id !== $p['generation_id']) {
                $errors[] = 'Ảnh #'.($i + 1).' ('.self::ref($p['generation_id'], $p['output_id']).') không tồn tại / đã tắt / không thuộc shop.';

                continue;
            }
            $rule = PostSamples::slideRule($sample, $i + $offset, count($parsed) + $offset);
            $kind = $output->generation->isOutfit() ? 'outfit' : 'product';
            if (in_array($rule['image'], ['product', 'outfit'], true) && $rule['image'] !== $kind) {
                $errors[] = 'Ảnh #'.($i + 1).": slide này cần ảnh {$rule['image']}, ảnh gửi là {$kind}.";
            }
            if ($p['text'] !== null) {
                if ($format === ContentPlanItem::FORMAT_VIDEO) {
                    $errors[] = 'Bài video không chèn chữ lên ảnh (bỏ text).';
                } elseif (! $rule['text']) {
                    $errors[] = 'Ảnh #'.($i + 1).': bài viết mẫu không cho chữ ở slide này.';
                } elseif (mb_strlen($p['text']) > 150) {
                    $errors[] = 'Ảnh #'.($i + 1).': chữ tối đa 150 ký tự.';
                }
                $hasText = true;
            }
            $g = $output->generation;
            $groupKeys[] = $g->product_id ? 'p'.$g->product_id : 'c'.$g->creative_id;
        }
        if ($sample['same_product'] && count(array_unique($groupKeys)) > 1) {
            $errors[] = "Bài viết mẫu \"{$sampleKey}\" cần mọi ảnh cùng 1 sản phẩm.";
        }
        if ($collage && $format === ContentPlanItem::FORMAT_PHOTO) {
            $this->validateRanking($hook, $parsed, $groupKeys, $errors);
        }
        if ($format === ContentPlanItem::FORMAT_PHOTO && ContentJobService::needsBuild($sampleKey, $hasText)
            && ! ContentJobService::hasWorker($channel->user_id)) {
            $errors[] = 'Shop chưa được gắn worker dựng nội dung (content-worker), không dựng được bài ảnh có chữ.';
        }

        $textTemplateId = null;
        if ($hasText && $format === ContentPlanItem::FORMAT_PHOTO) {
            $textTemplateId = $this->approvedTemplateId($channel, $raw, $sampleKey, $errors);
        }

        // Không trùng y hệt (cùng bộ ảnh + bài viết mẫu + hook)
        $signature = self::signature($sampleKey, $hook, array_column($parsed, 'output_id'));
        if (in_array($signature, $signatures, true)) {
            $errors[] = 'Trùng y hệt bài khác của kênh (cùng bộ ảnh + bài viết mẫu + hook). Đổi hook hoặc ảnh.';
        }

        if ($errors) {
            return [[], $errors];
        }

        return [[
            'sample_key' => $sampleKey,
            'hook' => $hook,
            'format' => $format,
            'video_template_id' => $template?->id,
            'images' => $parsed,
            'outfit' => null,
            'text_template_id' => $textTemplateId,
            ...$this->caption($channel, $raw),
            '_signature' => $signature,
        ], []];
    }

    /**
     * Template chữ của bài: phải ĐÃ DUYỆT và GẮN với loại bài viết mẫu $sampleKey (template chưa gắn loại bài nào không
     * dùng được, người dùng chốt 2026-11-21). Gửi text_template_id sai -> lỗi; bỏ trống -> template hợp lệ đầu tiên.
     */
    private function approvedTemplateId(Channel $channel, array $raw, string $sampleKey, array &$errors): ?int
    {
        $usable = fn () => $channel->textTemplates()->where('status', ChannelTextTemplate::APPROVED)->forSample($sampleKey);
        $wanted = (int) ($raw['text_template_id'] ?? 0);
        if ($wanted) {
            $id = $usable()->whereKey($wanted)->value('id');
            if (! $id) {
                $errors[] = "text_template_id {$wanted} không phải template chữ đã duyệt gắn với bài viết mẫu \"{$sampleKey}\".";
            }

            return $id;
        }
        $id = $usable()->orderBy('id')->value('id');
        if (! $id) {
            $errors[] = "Chưa có template chữ đã duyệt gắn với bài viết mẫu \"{$sampleKey}\" (chủ shop gắn loại bài ở tab Template chữ).";
        }

        return $id;
    }

    /** Caption theo cài đặt AI của kênh: tiêu đề / nội dung cắt theo giới hạn, hashtag chỉ giữ cái trong kho (tối đa N). */
    private function caption(Channel $channel, array $raw): array
    {
        $pool = collect($channel->hashtags()->pluck('name'))->keyBy(fn ($n) => Hashtag::slugFor($n));
        $hashtags = collect(SocialPost::normalizeHashtags(is_array($raw['hashtags'] ?? null) ? $raw['hashtags'] : []))
            ->map(fn ($n) => $pool->get(Hashtag::slugFor($n)))
            ->filter()->unique()->take((int) config('ai90.social.ai_max_hashtags'))->values()->all();

        return [
            'title' => Str::limit(trim(preg_replace('/\s+/u', ' ', (string) ($raw['title'] ?? ''))), min((int) $channel->title_max, SocialPost::TITLE_MAX), ''),
            'content' => Str::limit(trim((string) ($raw['content'] ?? '')), (int) $channel->content_max, ''),
            'hashtags' => $hashtags,
        ];
    }

    /**
     * Bài lấy ảnh bằng lô phối đồ: outfit.count = tổng số ảnh của lô (gồm 1 bìa), trong khoảng slide của bài viết mẫu và
     * giới hạn lô phối đồ; outfit.product_id (không bắt buộc) = sản phẩm đang hoạt động có ảnh của shop. Shop phải được
     * gắn worker phối đồ. Chỉ bài ảnh, A51 không chèn chữ: worker phối đồ in hook lên ảnh bìa theo text_template_id của bài.
     * Bài viết mẫu có 'outfit_labels' (outfit_by_day): outfit.labels = đúng count - 1 nhãn ngày, worker in lên ảnh outfit
     * thay "Outfit N".
     *
     * @return array{count: int, product_id: ?int, tags: string[], labels?: string[]}
     */
    private function validateOutfit(Channel $channel, array $sample, array $raw, string $format, array &$errors): array
    {
        if ($format !== ContentPlanItem::FORMAT_PHOTO) {
            $errors[] = 'Bài lô phối đồ chỉ dùng cho slot ảnh.';
        }
        $min = max($sample['slides']['min'], OutfitBatchService::MIN_IMAGES);
        $max = min($sample['slides']['max'], OutfitBatchService::MAX_IMAGES);
        $count = (int) ($raw['outfit']['count'] ?? 0);
        if ($count < $min || $count > $max) {
            $errors[] = "outfit.count (số ảnh của lô phối đồ, gồm 1 bìa) phải từ {$min} đến {$max}.";
        }
        $productId = isset($raw['outfit']['product_id']) ? (int) $raw['outfit']['product_id'] : null;
        if ($productId && ! Product::query()->where('user_id', $channel->user_id)->active()->whereHas('images')->whereKey($productId)->exists()) {
            $errors[] = "outfit.product_id {$productId} không phải sản phẩm đang hoạt động (có ảnh) của shop.";
        }
        if (! Worker::query()->assignedTo($channel->user_id, Worker::TYPE_OUTFIT)->exists()) {
            $errors[] = 'Shop chưa được gắn worker phối đồ, không tạo được lô phối đồ.';
        }
        // Hashtag chủ đề: worker phối đồ tìm outfit trên OOTD theo các hashtag này (dạng OOTD: không dấu, liền, thường)
        $tags = collect(is_array($raw['outfit']['tags'] ?? null) ? $raw['outfit']['tags'] : [])
            ->map(fn ($t) => self::ootdTag((string) $t))->filter()->unique()->values()->all();
        if (! $tags || count($tags) > self::OUTFIT_TAGS_MAX) {
            $errors[] = 'outfit.tags (hashtag chủ đề để tìm outfit) cần 1-'.self::OUTFIT_TAGS_MAX.' hashtag.';
        }

        $outfit = ['count' => $count, 'product_id' => $productId ?: null, 'tags' => $tags];
        if ($sample['outfit_labels'] ?? false) {
            $outfit['labels'] = $this->validateLabels($raw['outfit']['labels'] ?? null, $count - 1, $errors);
        }

        return $outfit;
    }

    /** Bài Top N: N trong hook "Top N" = số ảnh; ảnh thứ k có chữ bắt đầu "#k"; N mẫu khác nhau (chỉ kiểm tra dữ liệu). */
    private function validateRanking(string $hook, array $parsed, array $groupKeys, array &$errors): void
    {
        $n = count($parsed);
        if (preg_match('/top\s*(\d+)/iu', $hook, $m) && (int) $m[1] !== $n) {
            $errors[] = "Hook ghi Top {$m[1]} nhưng bài có {$n} ảnh xếp hạng.";
        }
        foreach ($parsed as $i => $p) {
            $k = $i + 1;
            if (! preg_match('/^#'.$k.'(?!\d)/u', (string) $p['text'])) {
                $errors[] = "Ảnh #{$k}: chữ phải bắt đầu bằng \"#{$k} \" (vd \"#{$k} Cao gót mũi nhọn, sang chảnh\").";
            }
        }
        if (count(array_unique($groupKeys)) < count($groupKeys)) {
            $errors[] = 'Top N cần mỗi ảnh là 1 mẫu KHÁC NHAU.';
        }
    }

    /** Nhãn ngày của ảnh outfit: đúng $need nhãn, bỏ emoji / khoảng trắng thừa, mỗi nhãn 1..LABEL_MAX ký tự, không trùng. */
    private function validateLabels(mixed $raw, int $need, array &$errors): array
    {
        $labels = collect(is_array($raw) ? array_values($raw) : [])
            ->map(fn ($l) => trim(preg_replace(['/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u', '/\s+/u'], ['', ' '], (string) $l)))
            ->all();
        if (count($labels) !== $need) {
            $errors[] = "outfit.labels cần đúng {$need} nhãn ngày (= outfit.count - 1, mỗi ảnh outfit 1 nhãn), đang có ".count($labels).'.';

            return $labels;
        }
        foreach ($labels as $i => $label) {
            if ($label === '' || mb_strlen($label) > PostSamples::LABEL_MAX) {
                $errors[] = 'outfit.labels #'.($i + 1).' phải có 1-'.PostSamples::LABEL_MAX.' ký tự (không emoji).';
            }
        }
        if (count(array_unique(array_map('mb_strtolower', $labels))) !== count($labels)) {
            $errors[] = 'outfit.labels không được trùng nhau.';
        }

        return $labels;
    }

    public const OUTFIT_TAGS_MAX = 5;

    /** Như Hashtag::normalize của OOTD: "Đi tiệc" -> "ditiec". */
    public static function ootdTag(string $tag): string
    {
        $t = Str::ascii(str_replace(['đ', 'Đ'], 'd', mb_strtolower($tag)));

        return preg_replace('/[^a-z0-9]/', '', $t);
    }

    public static function signature(string $sampleKey, string $hook, array $outputIds): string
    {
        return $sampleKey.'|'.mb_strtolower(trim($hook)).'|'.implode(',', $outputIds);
    }

    /** Chữ ký các bài đã có của kênh + các bài trong kỳ (để chặn trùng y hệt). */
    private function existingSignatures(Channel $channel, ?ContentPlan $plan, ?int $exceptPostId = null): array
    {
        $posts = $channel->posts()->whereNotNull('sample_key')->with('images')
            ->when($exceptPostId, fn ($q) => $q->whereKeyNot($exceptPostId))
            ->get()
            ->map(fn (SocialPost $p) => self::signature($p->sample_key, (string) $p->hook, $p->images->pluck('output_id')->filter()->values()->all()));
        $items = $plan
            ? $plan->items()->where('status', ContentPlanItem::DRAFT)->get()
                ->map(fn (ContentPlanItem $i) => self::signature($i->sample_key, $i->hook, array_column($i->images, 'output_id')))
            : collect();

        return [...$posts->all(), ...$items->all()];
    }

    /** @return string[] */
    private function lineupWarnings(ContentPlan $plan, ChannelLineup $lineup): array
    {
        $items = $plan->items()->where('status', '!=', ContentPlanItem::CANCELLED)->get();
        $total = $items->count();
        $warnings = [];
        $empty = count($plan->slots ?? []) - $total;
        if ($empty > 0) {
            $warnings[] = "Còn {$empty} slot chưa có bài.";
        }
        if ($total === 0) {
            return $warnings;
        }
        $counts = $items->countBy('sample_key');
        foreach ($lineup->items as $row) {
            $actual = round(($counts[$row['sample_key']] ?? 0) * 100 / $total);
            if (abs($actual - $row['percent']) > self::LINEUP_TOLERANCE) {
                $name = PostSamples::get($row['sample_key'])['name'] ?? $row['sample_key'];
                $warnings[] = "\"{$name}\": tuyến {$row['percent']}%, kỳ này {$actual}%.";
            }
        }

        return $warnings;
    }

    // ------------------------------------------------------------------ Duyệt / huỷ

    /** Duyệt kỳ: mỗi bài draft thành 1 bài đăng của kênh (mục 5.9). */
    public function approve(ContentPlan $plan): ContentPlan
    {
        return DB::transaction(function () use ($plan) {
            $plan = ContentPlan::query()->lockForUpdate()->findOrFail($plan->id);
            if ($plan->status !== ContentPlan::DRAFT) {
                throw new BusinessException('PLAN_NOT_DRAFT', 'Chỉ duyệt được kỳ đang chờ duyệt.', 409);
            }
            $channel = $plan->channel()->firstOrFail();
            if (! $channel->worker_id) {
                throw new BusinessException('CHANNEL_NO_WORKER', 'Kênh chưa có worker đăng bài.', 409);
            }
            foreach ($plan->items()->where('status', ContentPlanItem::DRAFT)->get() as $item) {
                $post = $this->createPost($channel, $item);
                $item->update(['status' => ContentPlanItem::POSTED, 'social_post_id' => $post->id]);
            }
            $plan->update(['status' => ContentPlan::APPROVED, 'approved_at' => now()]);
            $this->audit->log('content_plan.approve', $plan, ['posts' => $plan->items()->where('status', ContentPlanItem::POSTED)->count()]);

            return $plan;
        });
    }

    private function createPost(Channel $channel, ContentPlanItem $item): SocialPost
    {
        $template = $item->video_template_id ? VideoTemplate::query()->find($item->video_template_id) : null;
        $post = new SocialPost([
            'user_id' => $channel->user_id,
            'channel_id' => $channel->id,
            'worker_id' => $channel->worker_id,
            'plan_item_id' => $item->id,
            'sample_key' => $item->sample_key,
            'hook' => $item->hook,
            'source' => SocialPost::SOURCE_PLAN,
            'type' => $item->format === ContentPlanItem::FORMAT_VIDEO ? SocialPost::TYPE_VIDEO : SocialPost::TYPE_PHOTO,
            ...VideoPostService::templateSnapshot($template),
            'scheduled_at' => $item->scheduled_at,
            'title' => $item->title,
            'content' => (string) $item->content,
            'hashtags' => $item->hashtags ?? [],
            'platforms' => [SocialPlatform::Tiktok->value],
            'idempotency_key' => 'plan-item-'.$item->id,
            'ai_content' => false,
        ]);
        $post->queuePlatforms();
        $post->syncStatus();
        if ($item->outfit) {
            // Ảnh tạo bằng lô phối đồ mới: bài chờ ảnh xong (SocialPostService::advanceIfReady) rồi vào hàng chờ đăng
            $batch = $this->createOutfitBatch($channel, $item->outfit, 'plan-item-'.$item->id, $item->hook, $item->text_template_id);
            $post->status = SocialPostStatus::Generating;
            $post->image_batch_id = $batch->id;
            $post->save();
            $this->syncImages($post, $this->batchImages($batch), null);

            return $post;
        }
        $post->save();
        $this->syncImages($post, $item->images, $item->text_template_id);
        $this->contentJobs->queueForPost($post);

        return $post;
    }

    /** Tạo 1 lô phối đồ cho bài (worker phối đồ đầu tiên được gắn cho shop, chèn logo nếu shop có logo). */
    private function createOutfitBatch(Channel $channel, array $outfit, string $key, string $hook, ?int $textTemplateId): ImageBatch
    {
        $user = $channel->user()->firstOrFail();
        $workerId = Worker::query()->assignedTo($user->id, Worker::TYPE_OUTFIT)->orderBy('id')->value('id')
            ?? throw new BusinessException('OUTFIT_WORKER_REQUIRED', 'Shop chưa được gắn worker phối đồ.', 409);
        // Có product_id: lô gắn sản phẩm đó (bìa dùng ảnh giày của sản phẩm); không có: lô không gắn sản phẩm
        // (công tắc "Dùng ảnh sản phẩm" tắt ở luồng tạo lô phối đồ hiện tại)
        $productId = ! empty($outfit['product_id']) ? (int) $outfit['product_id'] : null;
        $batches = $this->outfitBatches->create($user, 1, (int) $outfit['count'], (bool) $user->logoPath(), $key, $workerId,
            $productId ? [$productId] : null, (bool) $productId, $hook, $outfit['tags'] ?? [], $textTemplateId, $outfit['labels'] ?? null);

        return $batches[0] ?? throw new BusinessException('OUTFIT_NO_PRODUCT', 'Không tạo được lô phối đồ.', 422);
    }

    /** Ảnh của lô theo thứ tự (bìa trước) dạng [{generation_id, output_id, text}]. */
    private function batchImages(ImageBatch $batch): array
    {
        return $batch->images()->orderBy('position')->get()
            ->map(fn ($i) => ['generation_id' => $i->generation_id, 'output_id' => $i->output_id, 'text' => null])->all();
    }

    /** Ghi lại ảnh của bài theo thứ tự (kèm chữ + template chữ). */
    private function syncImages(SocialPost $post, array $images, ?int $textTemplateId): void
    {
        $post->images()->delete();
        foreach (array_values($images) as $i => $img) {
            $post->images()->create([
                'generation_id' => $img['generation_id'],
                'output_id' => $img['output_id'],
                'position' => $i + 1,
                'overlay_text' => $img['text'] ?? null,
                'text_template_id' => ($img['text'] ?? null) ? $textTemplateId : null,
            ]);
        }
    }

    /** Huỷ kỳ: bài draft -> cancelled; bài đã sinh mà worker chưa nhận -> xoá. */
    public function cancel(ContentPlan $plan): ContentPlan
    {
        return DB::transaction(function () use ($plan) {
            $plan = ContentPlan::query()->lockForUpdate()->findOrFail($plan->id);
            if ($plan->status === ContentPlan::CANCELLED) {
                return $plan;
            }
            foreach ($plan->items()->whereIn('status', [ContentPlanItem::DRAFT, ContentPlanItem::POSTED])->get() as $item) {
                $this->cancelItemInside($item);
            }
            $plan->update(['status' => ContentPlan::CANCELLED]);
            $this->audit->log('content_plan.cancel', $plan);

            return $plan;
        });
    }

    public function cancelItem(ContentPlanItem $item): void
    {
        DB::transaction(fn () => $this->cancelItemInside($item));
    }

    private function cancelItemInside(ContentPlanItem $item): void
    {
        if ($item->status === ContentPlanItem::POSTED && $item->social_post_id) {
            $post = SocialPost::query()->lockForUpdate()->find($item->social_post_id);
            if ($post && ! in_array($post->status, [SocialPostStatus::Generating, SocialPostStatus::PendingReview, SocialPostStatus::Queued], true)) {
                return; // worker đã nhận / đã đăng: giữ nguyên
            }
            $post?->delete();
        }
        $item->update(['status' => ContentPlanItem::CANCELLED, 'social_post_id' => null]);
    }

    /** Chủ shop sửa bài draft trước khi duyệt (cùng luật kiểm tra). */
    public function updateItem(Channel $channel, ContentPlanItem $item, array $raw): ContentPlanItem
    {
        if ($item->status !== ContentPlanItem::DRAFT) {
            throw new BusinessException('ITEM_NOT_DRAFT', 'Chỉ sửa được bài chưa duyệt.', 409);
        }
        $lineup = $channel->approvedLineup() ?? throw new BusinessException('LINEUP_REQUIRED', 'Kênh chưa có tuyến nội dung đã duyệt.', 409);
        $plan = $item->plan;
        $signatures = array_values(array_diff(
            $this->existingSignatures($channel, $plan),
            [self::signature($item->sample_key, $item->hook, array_column($item->images, 'output_id'))],
        ));
        [$data, $errors] = $this->validateItem($channel, $lineup, $raw, $item->format, $signatures);
        if ($errors) {
            throw ValidationException::withMessages(['item' => $errors]);
        }
        unset($data['_signature']);
        $item->update($data);

        return $item;
    }

    // ------------------------------------------------------------------ Làm lại bài

    public function requestRedo(SocialPost $post, bool $on): SocialPost
    {
        if ($on && ! in_array($post->status, [SocialPostStatus::Generating, SocialPostStatus::PendingReview, SocialPostStatus::Queued], true)) {
            throw new BusinessException('POST_NOT_EDITABLE', 'Worker đã nhận bài, không làm lại được.', 409);
        }
        if (! $post->channel_id) {
            throw new BusinessException('POST_NO_CHANNEL', 'Bài không thuộc kênh nào.', 409);
        }
        DB::transaction(function () use ($post, $on) {
            $post->redo_requested_at = $on ? now() : null;
            // Đánh dấu làm lại: worker không nhận bài trong lúc chờ lệnh làm lại
            if ($on && $post->status === SocialPostStatus::Queued) {
                $post->status = SocialPostStatus::PendingReview;
            }
            if (! $on && $post->status === SocialPostStatus::PendingReview && ! $post->last_error) {
                $post->syncStatus();
            }
            $post->save();
        });

        return $post->refresh();
    }

    /** Lệnh gửi nội dung làm lại cho 1 bài: giữ giờ đăng + định dạng, thay nội dung / ảnh, chèn chữ lại. */
    public function redo(SocialPost $post, array $raw): SocialPost
    {
        $channel = $post->channel ?? throw new BusinessException('POST_NO_CHANNEL', 'Bài không thuộc kênh nào.', 409);
        if (! in_array($post->status, [SocialPostStatus::Generating, SocialPostStatus::PendingReview, SocialPostStatus::Queued], true)) {
            throw new BusinessException('POST_NOT_EDITABLE', 'Worker đã nhận bài, không làm lại được.', 409);
        }
        $lineup = $channel->approvedLineup() ?? throw new BusinessException('LINEUP_REQUIRED', 'Kênh chưa có tuyến nội dung đã duyệt.', 409);
        $signatures = $this->existingSignatures($channel, null, $post->id);
        [$data, $errors] = $this->validateItem($channel, $lineup, $raw, $post->type, $signatures);
        if ($errors) {
            throw ValidationException::withMessages(['item' => $errors]);
        }

        return DB::transaction(function () use ($post, $data, $channel) {
            $post = SocialPost::query()->lockForUpdate()->findOrFail($post->id);
            if ($data['outfit']) {
                // Làm lại bài lô phối đồ: tạo lô phối đồ MỚI, bài chờ ảnh xong rồi vào hàng chờ đăng
                $batch = $this->createOutfitBatch($channel, $data['outfit'], 'redo-post-'.$post->id.'-'.now()->timestamp, $data['hook'],
                    $data['text_template_id']);
                $data['images'] = $this->batchImages($batch);
                $post->image_batch_id = $batch->id;
            }
            $template = $data['video_template_id'] ? VideoTemplate::query()->find($data['video_template_id']) : null;
            $post->fill([
                'sample_key' => $data['sample_key'],
                'hook' => $data['hook'],
                ...($post->isVideo() ? VideoPostService::templateSnapshot($template) : []),
                'title' => $data['title'],
                'content' => $data['content'],
                'hashtags' => $data['hashtags'],
                'redo_requested_at' => null,
                'last_error' => null,
            ]);
            $post->queuePlatforms();
            $post->syncStatus();
            $post->save();
            $this->syncImages($post, $data['images'], $data['text_template_id']);
            if ($data['outfit']) {
                $post->update(['status' => SocialPostStatus::Generating]);
            } else {
                $this->contentJobs->queueForPost($post);
            }
            if ($post->plan_item_id) {
                ContentPlanItem::query()->whereKey($post->plan_item_id)->update([
                    'outfit' => $data['outfit'] ? json_encode($data['outfit']) : null,
                    'sample_key' => $data['sample_key'], 'hook' => $data['hook'], 'images' => json_encode($data['images']),
                    'text_template_id' => $data['text_template_id'],
                    'title' => $data['title'], 'content' => $data['content'], 'hashtags' => json_encode($data['hashtags']),
                ]);
            }
            $this->audit->log('social_post.plan_redo', $post);

            return $post->refresh();
        });
    }

    /**
     * Chủ shop bỏ (từ chối) 1 template chữ: bài chưa được worker nhận đang dùng template đó chuyển sang template đã duyệt
     * khác của kênh rồi chèn chữ lại; bài kế hoạch chưa duyệt cũng đổi template. Không còn template đã duyệt nào khác mà
     * template đang được dùng -> 409 (để bài không mất kiểu chữ). Trả số bài đăng đã chuyển.
     */
    public function retireTemplate(Channel $channel, ChannelTextTemplate $template): int
    {
        return DB::transaction(function () use ($channel, $template) {
            $postIds = SocialPostImage::query()
                ->where('text_template_id', $template->id)
                ->whereNotNull('overlay_text')
                ->whereHas('post', fn ($q) => $q->where('channel_id', $channel->id)
                    ->whereIn('status', [SocialPostStatus::Generating, SocialPostStatus::PendingReview, SocialPostStatus::Queued]))
                ->distinct()->pluck('social_post_id');
            $items = ContentPlanItem::query()->where('text_template_id', $template->id)->where('status', ContentPlanItem::DRAFT)
                ->whereHas('plan', fn ($q) => $q->where('channel_id', $channel->id));
            // Template thay thế: template đã duyệt khác GẮN CÙNG loại bài viết mẫu với bài / bài kế hoạch
            $others = $channel->textTemplates()->where('status', ChannelTextTemplate::APPROVED)
                ->whereKeyNot($template->id)->orderBy('id')->get();
            $replacementFor = fn (?string $key) => ($key ? $others->first(fn (ChannelTextTemplate $t) => $t->usableFor($key)) : $others->first())?->id;
            $posts = SocialPost::query()->whereIn('id', $postIds)->get(['id', 'sample_key']);
            $planItems = (clone $items)->get(['id', 'sample_key']);
            $uncovered = $posts->pluck('sample_key')->merge($planItems->pluck('sample_key'))->unique()
                ->reject(fn ($key) => $replacementFor($key))->values();
            if ($uncovered->isNotEmpty()) {
                $names = $uncovered->map(fn ($key) => $key ? (PostSamples::get($key)['name'] ?? $key) : 'không loại')->implode(', ');
                throw new BusinessException('TEXT_TEMPLATE_IN_USE',
                    "Template này đang được bài chưa đăng dùng (loại bài: {$names}). Gắn loại bài đó cho 1 template đã duyệt khác trước khi bỏ template này.", 409);
            }

            $template->update(['status' => ChannelTextTemplate::REJECTED]);
            foreach ($planItems as $item) {
                ContentPlanItem::query()->whereKey($item->id)->update(['text_template_id' => $replacementFor($item->sample_key)]);
            }
            $moved = [];
            foreach ($posts as $row) {
                $post = SocialPost::query()->lockForUpdate()->find($row->id);
                if (! $post) {
                    continue;
                }
                $moved[$post->id] = $replacementFor($post->sample_key);
                $post->images()->where('text_template_id', $template->id)->update(['text_template_id' => $moved[$post->id]]);
                $this->contentJobs->queueForPost($post);
            }
            $this->audit->log('channel_text_template.retire', $template, ['moved_posts' => $postIds->count(), 'replacements' => $moved]);

            return $postIds->count();
        });
    }

    /** Chủ shop sửa chữ trên ảnh / template chữ của bài -> chèn lại (mục 5.10). */
    public function updateOverlays(SocialPost $post, array $rows): SocialPost
    {
        if (! in_array($post->status, [SocialPostStatus::Generating, SocialPostStatus::PendingReview, SocialPostStatus::Queued], true)) {
            throw new BusinessException('POST_NOT_EDITABLE', 'Worker đã nhận bài, không sửa được nữa.', 409);
        }
        if ($post->isVideo()) {
            throw new BusinessException('VIDEO_NO_OVERLAY', 'Bài video không chèn chữ.', 422);
        }
        $channel = $post->channel ?? throw new BusinessException('POST_NO_CHANNEL', 'Bài không thuộc kênh nào.', 409);
        // Chỉ template đã duyệt gắn với loại bài viết mẫu của bài
        $approved = $channel->textTemplates()->where('status', ChannelTextTemplate::APPROVED)->orderBy('id')->get()
            ->filter(fn (ChannelTextTemplate $t) => ! $post->sample_key || $t->usableFor($post->sample_key))
            ->pluck('id')->values()->all();

        return DB::transaction(function () use ($post, $rows, $approved) {
            $post = SocialPost::query()->lockForUpdate()->findOrFail($post->id);
            $images = $post->images()->get()->keyBy('id');
            foreach ($rows as $row) {
                $image = $images->get((int) ($row['image_id'] ?? 0));
                if (! $image) {
                    throw ValidationException::withMessages(['overlays' => 'Ảnh '.($row['image_id'] ?? '?').' không thuộc bài.']);
                }
                $text = trim((string) ($row['text'] ?? '')) ?: null;
                $templateId = $text ? (int) ($row['text_template_id'] ?? $image->text_template_id ?? 0) : null;
                if ($text && ! in_array($templateId, $approved, true)) {
                    $templateId = $approved[0] ?? null;
                    if (! $templateId) {
                        throw new BusinessException('TEXT_TEMPLATE_REQUIRED', 'Chưa có template chữ đã duyệt gắn với loại bài này.', 409);
                    }
                }
                $image->update(['overlay_text' => $text ? Str::limit($text, 150, '') : null, 'text_template_id' => $templateId]);
            }
            $post->queuePlatforms();
            $post->syncStatus();
            $post->last_error = null;
            $post->save();
            $this->contentJobs->queueForPost($post);

            return $post->refresh();
        });
    }
}
