<?php

namespace App\Services\Channel;

use App\Enums\SocialPlatform;
use App\Enums\SocialPostStatus;
use App\Exceptions\BusinessException;
use App\Models\Channel;
use App\Models\ChannelLineup;
use App\Models\ChannelTextTemplate;
use App\Models\ChannelTopic;
use App\Models\ContentPlan;
use App\Models\ContentPlanItem;
use App\Models\Generation;
use App\Models\GenerationOutput;
use App\Models\Hashtag;
use App\Models\ImageBatch;
use App\Models\PlanJob;
use App\Models\Product;
use App\Models\SocialPost;
use App\Models\SocialPostImage;
use App\Models\VideoTemplate;
use App\Models\Worker;
use App\Models\WorkerJob;
use App\Services\AuditLogger;
use App\Services\AutoBatchService;
use App\Services\ImageBatchService;
use App\Services\OutfitBatchService;
use App\Services\Social\VideoPostService;
use App\Support\PostSamples;
use App\Support\ProductTags;
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
    /** Số bài gần nhất gửi kèm để tránh trùng. */
    public const RECENT_POSTS = 60;

    /** Khoảng cách tối thiểu giữa 2 bài trong cùng khung giờ (phút). */
    public const MIN_GAP_MINUTES = 20;

    /** Không lập slot quá gần hiện tại (phút). */
    public const LEAD_MINUTES = 30;

    /** Tỉ lệ thực tế lệch tuyến quá số điểm % này thì cảnh báo. */
    public const LINEUP_TOLERANCE = 15;

    public function __construct(
        private AuditLogger $audit,
        private ContentJobService $contentJobs,
        private OutfitBatchService $outfitBatches,
        private ChannelTopicService $topics,
        private ProductTagService $productTags,
        private AutoBatchService $autoBatches,
    ) {}

    // ------------------------------------------------------------------ Context cho lệnh /lap-ke-hoach

    /**
     * Toàn bộ dữ liệu lệnh cần (mục 6.2). Kênh đủ điều kiện và chưa có kỳ draft: tạo / dùng lại kỳ preparing giữ slot.
     * $fill (chế độ TẠO BỔ SUNG, a90-fe note/xay-kenh-tiktok/worker-lap-ke-hoach.txt mục 5): plan = kỳ đó (chờ duyệt / đã
     * duyệt) với slots = CHỈ các slot còn thiếu bài (missingSlots), không tạo kỳ preparing.
     */
    public function context(Channel $channel, ?ContentPlan $fill = null, bool $redoOnly = false): array
    {
        $channel->loadMissing('worker', 'user');
        $missing = $channel->missing();
        $draft = $channel->plans()->where('status', ContentPlan::DRAFT)->latest('id')->first();
        $plan = null;
        if ($fill) {
            if (! in_array($fill->status, [ContentPlan::DRAFT, ContentPlan::APPROVED], true)) {
                throw new BusinessException('PLAN_CLOSED', 'Chỉ bổ sung được kỳ chờ duyệt hoặc đã duyệt.', 409);
            }
            // Tính lại slot theo cấu hình hiện tại (đã đổi số bài / khung giờ) rồi lưu để gửi bài đúng index
            $plan = $this->refitSlots($fill);
        } elseif (! $redoOnly && ! $draft && ! array_intersect($missing, ['worker', 'time_slots', 'lineup', 'text_templates'])) {
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
                // % giờ đăng video tính theo tuyến (tổng % bài viết mẫu chỉ làm video), không còn cấu hình riêng
                'video_percent' => $channel->videoPercent(),
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
                // Bài viết mẫu lấy ảnh bằng lô tự động mới (unboxing) cần worker tạo ảnh được gắn cho shop
                'image_worker' => Worker::query()->assignedTo($channel->user_id, Worker::TYPE_IMAGE)->exists(),
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
            // Không gửi ảnh / sản phẩm (tối ưu token, chu-de-va-tag-san-pham.txt mục 5): lệnh lấy sản phẩm đã gắn tag
            // qua GET channels/{id}/products?<tag>=...; product_tags nhắc chạy /gan-tag-san-pham khi còn sản phẩm chưa gắn
            'product_tags' => $this->productTags->counts($channel->user),
            // Giá trị tag dùng được khi lọc sản phẩm: lệnh tự chọn tag theo ghi chú chủ đề (chủ đề không còn bộ lọc tag)
            'tag_options' => ProductTags::options(),
            // Chủ đề đang bật của kênh {name, note} + số bài / lần dùng gần nhất theo loại bài (chủ đề tuỳ chọn: bài không gửi chủ đề = không chủ đề, 2026-11-24)
            'topics' => $this->topics->listFor($channel, true),
            'topic_repeat_days' => ChannelTopic::REPEAT_DAYS,
            'video_templates' => VideoTemplate::query()->where('user_id', $channel->user_id)->usable()->orderBy('name')
                ->get(['id', 'name', 'image_count']),
            'recent_posts' => $this->recentPosts($channel),
            'holidays' => VietnamHolidays::between($from, $to),
            'plan' => $plan ? [
                'id' => $plan->id,
                'start_date' => $plan->start_date->toDateString(),
                'days' => $plan->days,
                // Bổ sung: chỉ slot còn thiếu bài (giờ đăng chưa qua)
                'slots' => $fill ? $this->missingSlots($plan) : $plan->slots,
                'expires_at' => $fill ? null : $plan->created_at->addHours(ContentPlan::SLOT_HOLD_HOURS),
                'fill' => (bool) $fill,
                'status' => $plan->status,
                // Bổ sung: bài đang có của kỳ (để không lặp chủ đề / hook, giữ tỉ lệ tuyến)
                'items' => $fill ? $fill->items()->whereIn('slot_index', $this->activeSlotIndexes($fill))->with('topic:id,name')
                    ->orderBy('slot_index')->get()->map(fn (ContentPlanItem $i) => ['slot_index' => $i->slot_index,
                        'scheduled_at' => $i->scheduled_at?->toIso8601String(), 'sample_key' => $i->sample_key,
                        'topic' => $i->topic?->name, 'hook' => $i->hook])->all() : null,
            ] : null,
            'draft_plan_id' => $draft?->id,
            'redo_requests' => $this->redoRequests($channel),
            'now' => now()->toIso8601String(),
        ];
    }

    /**
     * Slot của kỳ còn THIẾU bài, tính theo CẤU HÌNH HIỆN TẠI của kênh (người dùng sửa cấu hình rồi bấm "Tạo bổ sung"):
     * mỗi ngày của kỳ cần posts_per_day bài; bài đang dùng giữ nguyên giờ; ngày còn thiếu thì dùng lại giờ trống cũ của kỳ
     * rồi thêm giờ mới trong khung giờ hiện tại (cách bài khác >= MIN_GAP_MINUTES, sau LEAD_MINUTES). Cấu hình giảm số bài
     * thì giờ trống thừa bị bỏ. Không lưu (đếm cho nút); refitSlots() lưu lại khi worker lấy context bổ sung.
     *
     * @return array<int, array{index: int, at: string, format: string}>
     */
    public function missingSlots(ContentPlan $plan): array
    {
        return $this->fillPlanSlots($plan)['missing'];
    }

    /** Lưu lại slot của kỳ = slot có bài + slot thiếu (missingSlots) để gửi bài bổ sung đúng index. */
    public function refitSlots(ContentPlan $plan): ContentPlan
    {
        $fit = $this->fillPlanSlots($plan);
        $plan->update(['slots' => collect([...$fit['active'], ...$fit['missing']])->sortBy('at')->values()->all()]);

        return $plan;
    }

    /** @return array{active: array, missing: array} */
    private function fillPlanSlots(ContentPlan $plan): array
    {
        $channel = $plan->channel()->firstOrFail();
        $activeIdx = $this->activeSlotIndexes($plan);
        $slots = collect($plan->slots ?? []);
        $active = $slots->filter(fn ($s) => in_array((int) $s['index'], $activeIdx, true))->values();
        $tz = config('app.timezone');
        $dayOf = fn ($s) => CarbonImmutable::parse($s['at'])->setTimezone($tz)->toDateString();
        $after = now()->addMinutes(self::LEAD_MINUTES);
        $nextIndex = max([-1, ...$slots->pluck('index')->map(fn ($i) => (int) $i)->all(),
            ...$plan->items()->pluck('slot_index')->map(fn ($i) => (int) $i)->all()]) + 1;
        $windows = collect($channel->time_slots ?? [])->sortBy('start')->values();
        $perDay = (int) $channel->posts_per_day;
        $missing = [];
        $start = CarbonImmutable::parse($plan->start_date->toDateString(), $tz);
        for ($d = 0; $d < $plan->days; $d++) {
            $day = $start->addDays($d);
            $date = $day->toDateString();
            $taken = $active->filter(fn ($s) => $dayOf($s) === $date)->map(fn ($s) => CarbonImmutable::parse($s['at']))->values()->all();
            $need = $perDay - count($taken);
            if ($need <= 0) {
                continue;
            }
            $fits = function (CarbonImmutable $at) use (&$taken, $after): bool {
                if ($at->lte($after)) {
                    return false;
                }
                foreach ($taken as $t) {
                    if (abs($t->diffInMinutes($at, false)) < self::MIN_GAP_MINUTES) {
                        return false;
                    }
                }

                return true;
            };
            // 1. Giờ trống cũ của kỳ trong ngày (giữ giờ đã cấp)
            foreach ($slots->filter(fn ($s) => ! in_array((int) $s['index'], $activeIdx, true) && $dayOf($s) === $date)->sortBy('at') as $s) {
                $at = CarbonImmutable::parse($s['at']);
                if ($need > 0 && $fits($at)) {
                    $missing[] = $s;
                    $taken[] = $at;
                    $need--;
                }
            }
            // 2. Giờ mới: giữa mỗi phần của khung giờ hiện tại (chia posts_per_day vào các khung như computeSlots)
            if ($need > 0 && $windows->isNotEmpty()) {
                $per = array_fill(0, $windows->count(), intdiv($perDay, $windows->count()));
                for ($i = 0; $i < $perDay % $windows->count(); $i++) {
                    $per[$i]++;
                }
                foreach ($windows as $w => $window) {
                    $from = $day->setTimeFromTimeString($window['start']);
                    $to = $day->setTimeFromTimeString($window['end']);
                    $count = max(1, $per[$w]);
                    $segment = intdiv(max(0, (int) $from->diffInMinutes($to)), $count);
                    for ($k = 0; $k < $count && $need > 0; $k++) {
                        $at = $from->addMinutes($segment * $k + intdiv($segment, 2))->second(0);
                        if ($fits($at)) {
                            $missing[] = ['index' => $nextIndex++, 'at' => $at->toIso8601String(), 'format' => ContentPlanItem::FORMAT_PHOTO];
                            $taken[] = $at;
                            $need--;
                        }
                    }
                }
            }
        }
        // Tỉ lệ video theo cấu hình hiện tại: slot mới thành video cho đủ số video cả kỳ
        $videos = (int) round(($active->count() + count($missing)) * $channel->videoPercent() / 100)
            - $active->where('format', ContentPlanItem::FORMAT_VIDEO)->count()
            - collect($missing)->where('format', ContentPlanItem::FORMAT_VIDEO)->count();
        foreach ($missing as $i => $s) {
            if ($videos <= 0) {
                break;
            }
            if ($s['format'] !== ContentPlanItem::FORMAT_VIDEO && ! $slots->contains('index', $s['index'])) {
                $missing[$i]['format'] = ContentPlanItem::FORMAT_VIDEO;
                $videos--;
            }
        }

        return ['active' => $active->all(), 'missing' => collect($missing)->sortBy('at')->values()->all()];
    }

    /** Slot đã có bài đang dùng: bài chờ duyệt, hoặc đã thành bài đăng mà bài đăng còn. */
    private function activeSlotIndexes(ContentPlan $plan): array
    {
        return $plan->items()->where(fn ($q) => $q->where('status', ContentPlanItem::DRAFT)
            ->orWhere(fn ($q) => $q->where('status', ContentPlanItem::POSTED)->whereHas('post')))
            ->pluck('slot_index')->map(fn ($i) => (int) $i)->all();
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
     * Bài video = làm tròn(tổng x % video của tuyến / 100) (Channel::videoPercent), rải đều.
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
        $videos = (int) round($total * $channel->videoPercent() / 100);
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

    private function recentPosts(Channel $channel): Collection
    {
        return $channel->posts()->with('images', 'topic:id,name')->orderByDesc('scheduled_at')->orderByDesc('id')->limit(self::RECENT_POSTS)->get()
            ->map(fn (SocialPost $p) => [
                'id' => $p->id,
                'scheduled_at' => $p->scheduled_at?->toIso8601String(),
                'sample_key' => $p->sample_key,
                'topic' => $p->topic?->name,
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
        // Kỳ đã duyệt chỉ nhận bài BỔ SUNG cho slot còn thiếu (nút "Tạo bổ sung"): bài thành bài đăng ngay
        if (! in_array($plan->status, [ContentPlan::PREPARING, ContentPlan::DRAFT, ContentPlan::APPROVED], true)) {
            throw new BusinessException('PLAN_CLOSED', 'Kỳ kế hoạch đã huỷ, không nhận thêm bài.', 409);
        }
        $approved = $plan->status === ContentPlan::APPROVED;
        $missing = $plan->status === ContentPlan::PREPARING ? null : array_column($this->missingSlots($plan), 'index');
        if ($plan->status === ContentPlan::PREPARING && $plan->created_at->lt(now()->subHours(ContentPlan::SLOT_HOLD_HOURS))) {
            throw new BusinessException('PLAN_EXPIRED', 'Slot giờ đã hết hạn giữ chỗ, chạy lại lệnh để lấy slot mới.', 409);
        }
        $lineup = $channel->approvedLineup()
            ?? throw new BusinessException('LINEUP_REQUIRED', 'Kênh chưa có tuyến nội dung đã duyệt.', 409);

        $accepted = [];
        $rejected = [];
        $taken = $this->activeSlotIndexes($plan);
        $signatures = $this->existingSignatures($channel, $plan);

        foreach ($items as $raw) {
            $slotIndex = $raw['slot_index'] ?? null;
            $slot = is_numeric($slotIndex) ? $plan->slot((int) $slotIndex) : null;
            if (! $slot) {
                $rejected[] = ['slot_index' => $slotIndex, 'errors' => ['slot_index không có trong kỳ.']];

                continue;
            }
            if ($missing !== null && ! in_array((int) $slotIndex, $taken, true) && ! in_array((int) $slotIndex, $missing, true)) {
                $rejected[] = ['slot_index' => $slotIndex, 'errors' => ['Slot đã qua giờ đăng (hoặc quá sát giờ), không bổ sung được.']];

                continue;
            }
            if (in_array((int) $slotIndex, $taken, true)) {
                $rejected[] = ['slot_index' => $slotIndex, 'errors' => ['Slot này đã có bài.']];

                continue;
            }
            $at = CarbonImmutable::parse($slot['at']);
            [$data, $errors] = $this->validateItem($channel, $lineup, $raw, $slot['format'], $signatures, $at);
            if ($errors) {
                $rejected[] = ['slot_index' => $slotIndex, 'errors' => $errors];

                continue;
            }
            $signature = $data['_signature'];
            $topicId = $this->topics->resolve($channel, $data['_topic']);
            unset($data['_signature'], $data['_topic']);
            // Bổ sung slot có bài cũ đã huỷ / mất bài đăng: bỏ dòng cũ (slot_index duy nhất trong kỳ)
            $plan->items()->where('slot_index', (int) $slotIndex)->delete();
            $item = $plan->items()->create([
                ...$data,
                'topic_id' => $topicId,
                'slot_index' => (int) $slotIndex,
                'scheduled_at' => CarbonImmutable::parse($slot['at']),
                'status' => ContentPlanItem::DRAFT,
            ]);
            if ($approved) {
                DB::transaction(function () use ($plan, $item) {
                    $post = $this->createPost($plan->channel()->firstOrFail(), $item);
                    $item->update(['status' => ContentPlanItem::POSTED, 'social_post_id' => $post->id]);
                });
            }
            $taken[] = (int) $slotIndex;
            $signatures[] = $signature;
            $accepted[] = (int) $slotIndex;
        }

        $warnings = $this->lineupWarnings($plan->fresh(), $lineup);
        if ($accepted) {
            $plan->update(['status' => $approved ? ContentPlan::APPROVED : ContentPlan::DRAFT, 'warnings' => $warnings ?: null]);
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
    public function validateItem(Channel $channel, ChannelLineup $lineup, array $raw, string $format, array $signatures,
        CarbonImmutable $at, ?int $exceptPostId = null, ?int $exceptItemId = null): array
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
        // Bài không chữ trên ảnh / video (unboxing, video_type_01; người dùng chốt 2026-11-22): hook không hiện ở đâu ->
        // lệnh chỉ viết tiêu đề, A51 lấy TIÊU ĐỀ làm hook (danh sách hook theo chủ đề, chặn trùng, recent_posts)
        if (PostSamples::hookFromTitle($sample)) {
            $hook = $this->caption($channel, $raw)['title'];
            if ($hook === '') {
                $errors[] = "title bắt buộc (bài viết mẫu \"{$sampleKey}\" dùng tiêu đề làm hook, không cần gửi hook).";
            }
        } else {
            $hook = trim((string) ($raw['hook'] ?? ''));
            if ($hook === '' || mb_strlen($hook) > 200) {
                $errors[] = 'hook bắt buộc, tối đa 200 ký tự.';
            }
        }
        // Chủ đề (mọi loại bài): cùng chủ đề + cùng loại bài không lặp trong ChannelTopic::REPEAT_DAYS ngày
        $topic = $this->topics->check($channel, $raw, $sampleKey, $at, $errors, $exceptPostId, $exceptItemId);

        // Bài viết mẫu lấy ảnh bằng LÔ TỰ ĐỘNG mới (unboxing): không chọn ảnh, chỉ ghi 1 sản phẩm; ảnh không chữ
        if (($sample['image_source'] ?? PostSamples::SOURCE_LIBRARY) === PostSamples::SOURCE_PRODUCT_BATCH) {
            $productBatch = $this->validateProductBatch($channel, $raw, $format, $errors);
            $signature = self::signature($sampleKey, $hook, ['p'.$productBatch['product_id']]);
            if (in_array($signature, $signatures, true)) {
                $errors[] = 'Trùng hook + sản phẩm với bài khác của kênh. Đổi hook hoặc sản phẩm.';
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
                'outfit' => null,
                'product_batch' => $productBatch,
                'text_template_id' => null,
                ...$this->caption($channel, $raw),
                '_signature' => $signature,
                '_topic' => $topic,
            ], []];
        }

        // Bài viết mẫu lấy ảnh bằng LÔ PHỐI ĐỒ mới (vd lookbook): không chọn ảnh có sẵn, chỉ ghi số ảnh + sản phẩm
        if (($sample['image_source'] ?? PostSamples::SOURCE_LIBRARY) === PostSamples::SOURCE_OUTFIT_BATCH) {
            $outfit = $this->validateOutfit($channel, $sample, $raw, $format, $errors);
            if (($sample['cover_collage'] ?? false) && ! ContentJobService::hasWorker($channel->user_id)) {
                $errors[] = 'Shop chưa được gắn worker dựng nội dung (content-worker), không dựng được bìa bài này.';
            }
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
                'product_batch' => null,
                'text_template_id' => $textTemplateId,
                ...$this->caption($channel, $raw),
                '_signature' => $signature,
                '_topic' => $topic,
            ], []];
        }

        // Bài VIDEO từ ảnh sản phẩm (video_type_01): lệnh gửi product_id -> A51 chọn template + ảnh như "Tạo video", rồi kiểm
        // tra như bài video thường bên dưới. Chủ shop sửa bài (gửi lại images + video_template_id, không product_id): kiểm tra thường
        if (($sample['image_source'] ?? PostSamples::SOURCE_LIBRARY) === PostSamples::SOURCE_PRODUCT_VIDEO && ! empty($raw['product_id'])) {
            if ($format !== ContentPlanItem::FORMAT_VIDEO) {
                return [[], ["Bài viết mẫu \"{$sampleKey}\" chỉ dùng cho slot video."]];
            }
            $picked = $this->pickVideo($channel, (int) $raw['product_id']);
            if (is_string($picked)) {
                return [[], [$picked]];
            }
            $raw['video_template_id'] = $picked['template']->id;
            $raw['images'] = $picked['outputs']->map(fn ($o) => ['ref' => self::ref($o->generation_id, $o->id)])->values()->all();
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
            'product_batch' => null,
            'text_template_id' => $textTemplateId,
            ...$this->caption($channel, $raw),
            '_signature' => $signature,
            '_topic' => $topic,
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
     * outfit.texts (tuỳ chọn, mọi bài lô phối đồ, 2026-11-24; tên cũ outfit.labels): đúng count - 1 chữ theo thứ tự ảnh
     * outfit, "" = ảnh không chữ; worker in theo template chữ.
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
        // Bài "Outfit - theo sản phẩm" (one_shoe_many_looks): bắt buộc 1 sản phẩm có tag kieu (hashtag loại giày dùng chung
        // với OOTD, ProductTags::SHOE_TYPES) để worker chọn outfit cùng loại giày và Flow vẽ đúng đôi giày đó
        // (2026-11-24 sửa lại) mỗi ảnh outfit 1 sản phẩm KHÁC NHAU: outfit.product_ids = 4-6 sản phẩm theo thứ tự slide, mỗi
        // sản phẩm có tag kieu; outfit.count = số sản phẩm + 1 (bìa do content-worker dựng từ sản phẩm đầu)
        if (($sample['outfit_mode'] ?? null) === ImageBatch::OUTFIT_MODE_PRODUCT) {
            $outfit['mode'] = ImageBatch::OUTFIT_MODE_PRODUCT;
            $ids = collect(is_array($raw['outfit']['product_ids'] ?? null) ? $raw['outfit']['product_ids'] : [])
                ->map(fn ($id) => (int) $id)->filter()->values()->all();
            if (count($ids) < self::PRODUCT_LOOKS_MIN || count($ids) > self::PRODUCT_LOOKS_MAX) {
                $errors[] = 'Bài này cần outfit.product_ids = '.self::PRODUCT_LOOKS_MIN.'-'.self::PRODUCT_LOOKS_MAX.' sản phẩm (mỗi ảnh outfit 1 sản phẩm).';
            } elseif (count(array_unique($ids)) !== count($ids)) {
                $errors[] = 'outfit.product_ids không được trùng sản phẩm.';
            } elseif ($count !== count($ids) + 1) {
                $errors[] = 'outfit.count phải = số sản phẩm + 1 (bìa) = '.(count($ids) + 1).'.';
            } else {
                $products = Product::query()->where('user_id', $channel->user_id)->active()->whereHas('images')->whereKey($ids)
                    ->get(['id', 'tags'])->keyBy('id');
                foreach ($ids as $id) {
                    if (! $products->has($id)) {
                        $errors[] = "Sản phẩm {$id} không phải sản phẩm đang hoạt động (có ảnh) của shop.";
                    } elseif (! ProductTags::shoeMatch($products[$id]->tags['kieu'] ?? null)) {
                        $errors[] = "Sản phẩm {$id} chưa có tag kieu (loại giày): chạy /gan-tag-san-pham hoặc chọn sản phẩm khác.";
                    }
                }
            }
            $outfit['product_ids'] = $ids;
            $outfit['product_id'] = $ids[0] ?? null;
        }
        $texts = $raw['outfit']['texts'] ?? $raw['outfit']['labels'] ?? null;
        if ($texts !== null) {
            $texts = $this->validateTexts($texts, $count - 1, $errors);
            if (array_filter($texts, fn ($t) => $t !== '')) {
                $outfit['labels'] = $texts;
            }
        }

        return $outfit;
    }

    /**
     * Bài lấy ảnh bằng lô tự động mới (unboxing): product_batch.product_id = sản phẩm đang bật của shop ghép được với ít
     * nhất 1 nhóm Creative dùng cho kênh (dùng chung + riêng của kênh; cùng luật "Tạo tự động" ở trang lô). Shop phải
     * được gắn worker tạo ảnh. Chỉ bài ảnh.
     *
     * @return array{product_id: int}
     */
    private function validateProductBatch(Channel $channel, array $raw, string $format, array &$errors): array
    {
        if ($format !== ContentPlanItem::FORMAT_PHOTO) {
            $errors[] = 'Bài lô ảnh sản phẩm chỉ dùng cho slot ảnh.';
        }
        if (! empty($raw['images'])) {
            $errors[] = 'Bài viết mẫu này không chọn ảnh có sẵn (bỏ images): duyệt kỳ A51 tạo lô ảnh mới cho sản phẩm.';
        }
        $productId = (int) ($raw['product_batch']['product_id'] ?? 0);
        if (! $productId) {
            $errors[] = 'product_batch.product_id (sản phẩm để tạo lô ảnh) bắt buộc.';

            return ['product_id' => 0];
        }
        if (! Worker::query()->assignedTo($channel->user_id, Worker::TYPE_IMAGE)->exists()) {
            $errors[] = 'Shop chưa được gắn worker tạo ảnh, không tạo được lô ảnh sản phẩm.';
        }
        $user = $channel->user()->firstOrFail();
        if (! Product::query()->accessibleBy($user)->active()->whereKey($productId)->exists()) {
            $errors[] = "product_batch.product_id {$productId} không phải sản phẩm đang bật của shop.";
        } elseif (! $this->autoBatches->preview($user, 1, 1, [$productId], $channel->id)['plans']) {
            $errors[] = "Sản phẩm {$productId} không ghép được với nhóm Creative nào đang bán dùng cho kênh này (thiếu ảnh đúng trường nguyên liệu). Chọn sản phẩm khác.";
        }

        return ['product_id' => $productId];
    }

    /**
     * Bài video từ ảnh sản phẩm: video template đang bật của shop ngẫu nhiên trong các template mà sản phẩm đủ ảnh, ảnh =
     * ShopImageLibrary::productImagesForVideo (ít dùng cho video trước, mới trước) - như nút "Tạo video". Lỗi -> chuỗi lý do.
     *
     * @return array{template: VideoTemplate, outputs: Collection}|string
     */
    private function pickVideo(Channel $channel, int $productId): array|string
    {
        $user = $channel->user()->firstOrFail();
        if (! Product::query()->accessibleBy($user)->active()->whereKey($productId)->exists()) {
            return "product_id {$productId} không phải sản phẩm đang bật của shop.";
        }
        $library = app(\App\Services\ShopImageLibrary::class);
        $have = (int) ($library->countsPerProduct($user->id)->get($productId) ?? 0);
        $templates = VideoTemplate::query()->where('user_id', $user->id)->usable()->get();
        if ($templates->isEmpty()) {
            return 'Shop chưa có video template đang bật, không làm được bài video.';
        }
        $fit = $templates->filter(fn (VideoTemplate $t) => $t->image_count <= $have);
        if ($fit->isEmpty()) {
            return "Sản phẩm {$productId} mới có {$have} ảnh, không đủ cho video template nào (cần ít nhất {$templates->min('image_count')} ảnh). Chọn sản phẩm khác.";
        }
        $template = $fit->random();

        return ['template' => $template, 'outputs' => $library->productImagesForVideo($user->id, $productId, $template->image_count)];
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

    /** Chữ từng ảnh outfit: đúng $need phần tử, bỏ emoji / khoảng trắng thừa, mỗi chữ 0..LABEL_MAX ký tự ("" = không chữ), không trùng. */
    private function validateTexts(mixed $raw, int $need, array &$errors): array
    {
        $labels = collect(is_array($raw) ? array_values($raw) : [])
            ->map(fn ($l) => trim(preg_replace(['/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}\x{FE0F}\x{200D}]/u', '/\s+/u'], ['', ' '], (string) $l)))
            ->all();
        if (count($labels) !== $need) {
            $errors[] = "outfit.texts cần đúng {$need} phần tử (= outfit.count - 1, mỗi ảnh outfit 1 chữ, rỗng = không chữ), đang có ".count($labels).'.';

            return $labels;
        }
        foreach ($labels as $i => $label) {
            if (mb_strlen($label) > PostSamples::LABEL_MAX) {
                $errors[] = 'outfit.texts #'.($i + 1).' tối đa '.PostSamples::LABEL_MAX.' ký tự (không emoji).';
            }
        }
        $filled = array_filter($labels, fn ($l) => $l !== '');
        if (count(array_unique(array_map('mb_strtolower', $filled))) !== count($filled)) {
            $errors[] = 'outfit.texts không được trùng nhau.';
        }

        return $labels;
    }

    public const OUTFIT_TAGS_MAX = 5;

    /** Bài "Outfit - theo sản phẩm": số sản phẩm (= số ảnh outfit) mỗi bài. */
    public const PRODUCT_LOOKS_MIN = 4;

    public const PRODUCT_LOOKS_MAX = 6;

    /** Như Hashtag::normalize của OOTD: "Đi tiệc" -> "ditiec". */
    public static function ootdTag(string $tag): string
    {
        $t = Str::ascii(str_replace(['đ', 'Đ'], 'd', mb_strtolower($tag)));

        return preg_replace('/[^a-z0-9]/', '', $t);
    }

    public static function signature(string $sampleKey, string $hook, array $outputIds): string
    {
        // Gộp khoảng trắng / xuống dòng: hook bìa lô phối đồ có xuống dòng planner ngắt sẵn (2026-11-24) vẫn tính trùng như không ngắt
        return $sampleKey.'|'.mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $hook))).'|'.implode(',', $outputIds);
    }

    /** Chữ ký 1 bài kế hoạch: bài lô ảnh sản phẩm (chưa có ảnh) tính theo sản phẩm, còn lại theo bộ ảnh. */
    private static function itemSignature(ContentPlanItem $item): string
    {
        return self::signature($item->sample_key, $item->hook, $item->product_batch
            ? ['p'.($item->product_batch['product_id'] ?? 0)]
            : array_column($item->images ?? [], 'output_id'));
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
                ->map(fn (ContentPlanItem $i) => self::itemSignature($i))
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
            'topic_id' => $item->topic_id,
            'source' => SocialPost::SOURCE_PLAN,
            'type' => $item->format === ContentPlanItem::FORMAT_VIDEO ? SocialPost::TYPE_VIDEO : SocialPost::TYPE_PHOTO,
            ...VideoPostService::templateSnapshot($template),
            // Bài video: sản phẩm của ảnh (worker đăng bài nhận kèm khi dựng video, như bài "Tạo video")
            'product_id' => $item->format === ContentPlanItem::FORMAT_VIDEO && $item->images
                ? (Generation::query()->whereKey($item->images[0]['generation_id'])->value('product_id') ?: null) : null,
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
        if ($item->product_batch) {
            // Ảnh tạo bằng lô tự động mới (unboxing): bài chờ ảnh xong rồi vào hàng chờ đăng, ảnh không chữ
            $batch = $this->createProductBatch($channel, $item->product_batch, 'plan-item-'.$item->id);
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

    /** Tạo 1 lô tự động cho sản phẩm (planner chọn nhóm Creative của kênh ít lượt tạo nhất với sản phẩm), chèn logo nếu shop có logo. */
    private function createProductBatch(Channel $channel, array $spec, string $key): ImageBatch
    {
        $user = $channel->user()->firstOrFail();
        $result = $this->autoBatches->create($user, 1, (bool) $user->logoPath(), $key, null, null, [(int) $spec['product_id']], $channel->id);

        return $result['batches'][0] ?? throw new BusinessException('AUTO_NOTHING_TO_CREATE', 'Không tạo được lô ảnh cho sản phẩm.', 422);
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
            $productId ? [$productId] : null, (bool) $productId, $hook, $outfit['tags'] ?? [], $textTemplateId, $outfit['labels'] ?? null,
            $outfit['mode'] ?? null, $outfit['product_ids'] ?? null);

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
            $this->supersedeFillJobs($plan);
            $this->audit->log('content_plan.cancel', $plan);

            return $plan;
        });
    }

    /**
     * Dời lịch (người dùng chốt 2026-11-22), GIỮ NGUYÊN GIỜ đăng của từng bài:
     * - $itemIds null: dời CẢ KỲ sang ngày $date (ngày đầu kỳ mới): ngày đầu kỳ, slot giờ, mọi bài + bài đăng cùng lệch số
     *   ngày. Kỳ có bài worker đã nhận / đã đăng -> 409 (chọn từng bài để dời).
     * - $itemIds: các bài chọn sang ngày $date (giờ cũ); kỳ giữ ngày, giờ cũ thành thiếu bài ("Tạo bổ sung"). Bài worker
     *   đã nhận / đã đăng -> 409 ITEM_PUBLISHING.
     * Giờ mới phải sau now + LEAD_MINUTES và cách bài khác của kênh >= MIN_GAP_MINUTES. Trả số bài đã dời.
     */
    public function reschedule(ContentPlan $plan, string $date, ?array $itemIds): int
    {
        return DB::transaction(function () use ($plan, $date, $itemIds) {
            $plan = ContentPlan::query()->lockForUpdate()->findOrFail($plan->id);
            if (! in_array($plan->status, [ContentPlan::DRAFT, ContentPlan::APPROVED], true)) {
                throw new BusinessException('PLAN_CLOSED', 'Chỉ dời lịch được kỳ chờ duyệt / đã duyệt.', 409);
            }
            $tz = config('app.timezone');
            $target = CarbonImmutable::parse($date, $tz)->startOfDay();
            $whole = $itemIds === null;
            $delta = $whole ? (int) CarbonImmutable::parse($plan->start_date->toDateString(), $tz)->diffInDays($target, false) : 0;
            if ($whole && $delta === 0) {
                throw new BusinessException('SAME_DATE', 'Kỳ đã ở ngày này rồi.', 422);
            }

            $query = $plan->items()->with('post')->where('status', '!=', ContentPlanItem::CANCELLED);
            if (! $whole) {
                $query->whereIn('id', array_map('intval', $itemIds));
            }
            $items = $query->get();
            if (! $whole && $items->count() !== count(array_unique($itemIds))) {
                throw new BusinessException('ITEM_NOT_FOUND', 'Có bài không thuộc kỳ hoặc đã bị xoá.', 422);
            }
            $editable = [SocialPostStatus::Generating, SocialPostStatus::PendingReview, SocialPostStatus::Queued];
            // Bài đã thành bài đăng mà bài đăng bị xoá: không còn giờ đăng, dời theo kỳ nhưng không kiểm tra giờ
            $live = $items->filter(fn (ContentPlanItem $i) => $i->status === ContentPlanItem::DRAFT || $i->post);
            $locked = $live->filter(fn (ContentPlanItem $i) => $i->post && ! in_array($i->post->status, $editable, true));
            if ($locked->isNotEmpty()) {
                throw new BusinessException('ITEM_PUBLISHING', $whole
                    ? "Kỳ có {$locked->count()} bài worker đã nhận đăng / đã đăng, không dời cả kỳ được. Chọn từng bài còn lại để dời."
                    : 'Có bài worker đã nhận đăng / đã đăng, không dời được.', 409);
            }

            $newAt = fn (CarbonImmutable $at) => $whole
                ? $at->setTimezone($tz)->addDays($delta)
                : $target->setTimeFromTimeString($at->setTimezone($tz)->format('H:i:s'));
            $moves = $items->mapWithKeys(fn (ContentPlanItem $i) => [$i->id => $newAt(CarbonImmutable::parse($i->scheduled_at))]);

            // Kiểm tra giờ mới của bài còn dùng: chưa qua, không sát bài khác của kênh (kể cả các bài cùng dời)
            $earliest = now()->addMinutes(self::LEAD_MINUTES);
            $liveIds = $live->pluck('id')->all();
            $movedPostIds = $live->pluck('social_post_id')->filter()->all();
            $fmt = fn (CarbonImmutable $at) => $at->setTimezone($tz)->format('H:i d/m/Y');
            $past = $live->filter(fn (ContentPlanItem $i) => $moves[$i->id]->lte($earliest));
            if ($past->isNotEmpty()) {
                throw new BusinessException('TIME_PASSED', 'Giờ đăng mới đã qua hoặc quá sát hiện tại (cần sau '.self::LEAD_MINUTES.' phút): '
                    .$past->map(fn ($i) => $fmt($moves[$i->id]))->implode(', ').'.', 422);
            }
            $newTimes = $live->map(fn ($i) => $moves[$i->id])->values();
            if ($newTimes->isNotEmpty()) {
                $gap = self::MIN_GAP_MINUTES;
                $from = $newTimes->min()->subMinutes($gap);
                $to = $newTimes->max()->addMinutes($gap);
                $channel = $plan->channel()->firstOrFail();
                $others = $channel->posts()->whereIn('status', $editable)->whereNotIn('id', $movedPostIds ?: [0])
                    ->whereBetween('scheduled_at', [$from, $to])->pluck('scheduled_at')
                    ->merge(ContentPlanItem::query()->where('status', ContentPlanItem::DRAFT)->whereNotIn('id', $liveIds ?: [0])
                        ->whereHas('plan', fn ($q) => $q->where('channel_id', $channel->id)->whereIn('status', [ContentPlan::DRAFT, ContentPlan::APPROVED]))
                        ->whereBetween('scheduled_at', [$from, $to])->pluck('scheduled_at'))
                    ->map(fn ($at) => CarbonImmutable::parse($at))->merge($newTimes)->sort()->values();
                $clash = [];
                for ($k = 1; $k < $others->count(); $k++) {
                    if ($others[$k - 1]->diffInMinutes($others[$k]) < $gap
                        && ($newTimes->contains(fn ($t) => $t->eq($others[$k])) || $newTimes->contains(fn ($t) => $t->eq($others[$k - 1])))) {
                        $clash[] = $fmt($others[$k]);
                    }
                }
                if ($clash) {
                    throw new BusinessException('TIME_CONFLICT', "Giờ đăng mới sát bài khác của kênh (cần cách nhau >= {$gap} phút): "
                        .implode(', ', array_unique($clash)).'.', 409);
                }
            }

            // Dời: bài kế hoạch + slot giờ + bài đăng
            $slots = collect($plan->slots ?? []);
            foreach ($items as $item) {
                $at = $moves[$item->id];
                $item->update(['scheduled_at' => $at]);
                $item->post?->update(['scheduled_at' => $at]);
                $slots = $slots->map(fn ($s) => (int) $s['index'] === (int) $item->slot_index ? [...$s, 'at' => $at->toIso8601String()] : $s);
            }
            if ($whole) {
                // Slot trống (chưa có bài / bài đã huỷ) cũng lệch theo kỳ
                $itemSlots = $items->pluck('slot_index')->map(fn ($i) => (int) $i)->all();
                $slots = $slots->map(fn ($s) => in_array((int) $s['index'], $itemSlots, true)
                    ? $s : [...$s, 'at' => CarbonImmutable::parse($s['at'])->setTimezone($tz)->addDays($delta)->toIso8601String()]);
                $plan->items()->where('status', ContentPlanItem::CANCELLED)->get()
                    ->each(fn (ContentPlanItem $i) => $i->update(['scheduled_at' => CarbonImmutable::parse($i->scheduled_at)->addDays($delta)]));
                $plan->start_date = $target->toDateString();
            }
            $plan->slots = $slots->sortBy('at')->values()->all();
            $plan->save();
            $this->audit->log('content_plan.reschedule', $plan, [
                'date' => $target->toDateString(), 'whole' => $whole, 'item_ids' => $items->pluck('id')->all(),
            ]);

            return $items->count();
        });
    }

    /**
     * Xoá hẳn kỳ ĐÃ HUỶ (chủ shop bấm xoá ở tab Kế hoạch -> Đã huỷ). Bài trong kỳ xoá theo (cascade); bài đăng đã đăng
     * / đang đăng mà kỳ huỷ còn giữ lại thì vẫn giữ, chỉ bỏ liên kết (plan_item_id null).
     */
    public function delete(ContentPlan $plan): void
    {
        DB::transaction(function () use ($plan) {
            $plan = ContentPlan::query()->lockForUpdate()->findOrFail($plan->id);
            if ($plan->status !== ContentPlan::CANCELLED) {
                throw new BusinessException('PLAN_NOT_CANCELLED', 'Chỉ xoá được kỳ đã huỷ. Huỷ kỳ trước rồi xoá.', 409);
            }
            $this->audit->log('content_plan.delete', $plan, ['items' => $plan->items()->count()]);
            $plan->delete();
        });
    }

    /**
     * Nút X ở tab Kế hoạch (người dùng chốt 2026-11-21): XOÁ HẲN bài khỏi kỳ, kèm bài đăng của nó (chưa được worker nhận).
     * Giờ đăng đó thành thiếu bài -> "Tạo bổ sung" soạn bài khác. Bài đăng worker đã nhận / đã đăng: 409, không xoá.
     */
    public function deleteItem(ContentPlanItem $item): void
    {
        DB::transaction(function () use ($item) {
            $item = ContentPlanItem::query()->lockForUpdate()->findOrFail($item->id);
            $post = $item->social_post_id ? SocialPost::query()->lockForUpdate()->find($item->social_post_id) : null;
            if ($post && ! in_array($post->status, [SocialPostStatus::Generating, SocialPostStatus::PendingReview, SocialPostStatus::Queued], true)) {
                throw new BusinessException('ITEM_PUBLISHING', 'Bài đã được worker nhận đăng / đã đăng, không xoá được.', 409);
            }
            $plan = $item->plan()->firstOrFail();
            if ($post) {
                $this->deletePostWithJobs($post);
            }
            $this->audit->log('content_plan.delete_item', $plan, ['item_id' => $item->id, 'slot_index' => $item->slot_index, 'post_id' => $post?->id]);
            $item->delete();
            if ($plan->status === ContentPlan::DRAFT && ($lineup = $plan->channel()->firstOrFail()->approvedLineup())) {
                $plan->update(['warnings' => $this->lineupWarnings($plan->fresh(), $lineup) ?: null]);
            }
        });
    }

    private function cancelItemInside(ContentPlanItem $item): void
    {
        if ($item->status === ContentPlanItem::POSTED && $item->social_post_id) {
            $post = SocialPost::query()->lockForUpdate()->find($item->social_post_id);
            if ($post && ! in_array($post->status, [SocialPostStatus::Generating, SocialPostStatus::PendingReview, SocialPostStatus::Queued], true)) {
                return; // worker đã nhận / đã đăng: giữ nguyên
            }
            if ($post) {
                $this->deletePostWithJobs($post);
            }
        }
        $item->update(['status' => ContentPlanItem::CANCELLED, 'social_post_id' => null]);
    }

    /**
     * Xoá bài đăng của kỳ (huỷ kỳ / nút X) để worker KHÔNG nhận việc của bài nữa (người dùng yêu cầu 2026-11-22):
     * - việc dựng bài (content) / đăng bài (publish): xoá theo bài (worker_jobs.social_post_id cascade), worker đang làm
     *   gửi kết quả bị từ chối;
     * - ảnh lô phối đồ / lô ảnh sản phẩm tạo riêng cho bài khi duyệt: ảnh còn chờ (chưa worker nhận) huỷ luôn, hoàn lượt
     *   như "Hủy ảnh đang chờ". Ảnh worker đang tạo dở thì để xong (nằm lại ở lô).
     */
    private function deletePostWithJobs(SocialPost $post): void
    {
        if ($post->source === SocialPost::SOURCE_PLAN && $post->image_batch_id
            && ($batch = ImageBatch::query()->with('user')->find($post->image_batch_id))) {
            app(ImageBatchService::class)->cancelQueued($batch->user, $batch);
        }
        $post->delete();
    }

    /** Huỷ kỳ: việc "Tạo bổ sung" của kỳ chưa xong (chờ / đang chạy) thành superseded, worker điều phối không nhận nữa. */
    private function supersedeFillJobs(ContentPlan $plan): void
    {
        PlanJob::query()->where('channel_id', $plan->channel_id)
            ->whereIn('status', [WorkerJob::QUEUED, WorkerJob::PROCESSING])
            ->where('payload->plan_id', $plan->id)
            ->update(['status' => WorkerJob::SUPERSEDED, 'lease_expires_at' => null, 'finished_at' => now()]);
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
            [self::itemSignature($item)],
        ));
        // Bài lô ảnh sản phẩm: chủ shop sửa chữ / caption không gửi sản phẩm -> giữ sản phẩm cũ
        if (empty($raw['product_batch']) && $item->product_batch) {
            $raw['product_batch'] = $item->product_batch;
        }
        // Chủ shop sửa trên A51 không gửi chủ đề: giữ chủ đề cũ
        if (empty($raw['topic_id']) && empty($raw['topic'])) {
            if ($item->topic_id) {
                $raw['topic_id'] = $item->topic_id;
            } else {
                // Chủ đề của bài đã bị xoá: giữ "không xác định" (ChannelTopicService::NO_TOPIC; $raw đã qua validate nên
                // client không tự gửi được khoá này)
                $raw[ChannelTopicService::NO_TOPIC] = true;
            }
        }
        [$data, $errors] = $this->validateItem($channel, $lineup, $raw, $item->format, $signatures,
            CarbonImmutable::parse($item->scheduled_at), null, $item->id);
        if ($errors) {
            throw ValidationException::withMessages(['item' => $errors]);
        }
        $data['topic_id'] = $this->topics->resolve($channel, $data['_topic']);
        unset($data['_signature'], $data['_topic']);
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
        // Giao worker điều phối làm lại ngay (không chờ lượt lập kế hoạch sau)
        if ($on) {
            app(PlanJobService::class)->queueRedo($post->channel()->firstOrFail());
        }

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
        [$data, $errors] = $this->validateItem($channel, $lineup, $raw, $post->type, $signatures,
            CarbonImmutable::parse($post->scheduled_at), $post->id, $post->plan_item_id);
        if ($errors) {
            throw ValidationException::withMessages(['item' => $errors]);
        }

        return DB::transaction(function () use ($post, $data, $channel) {
            $post = SocialPost::query()->lockForUpdate()->findOrFail($post->id);
            $topicId = $this->topics->resolve($channel, $data['_topic']);
            if ($data['outfit']) {
                // Làm lại bài lô phối đồ: tạo lô phối đồ MỚI, bài chờ ảnh xong rồi vào hàng chờ đăng
                $batch = $this->createOutfitBatch($channel, $data['outfit'], 'redo-post-'.$post->id.'-'.now()->timestamp, $data['hook'],
                    $data['text_template_id']);
                $data['images'] = $this->batchImages($batch);
                $post->image_batch_id = $batch->id;
            } elseif ($data['product_batch']) {
                // Làm lại bài lô ảnh sản phẩm: tạo lô tự động MỚI
                $batch = $this->createProductBatch($channel, $data['product_batch'], 'redo-post-'.$post->id.'-'.now()->timestamp);
                $data['images'] = $this->batchImages($batch);
                $post->image_batch_id = $batch->id;
            }
            $template = $data['video_template_id'] ? VideoTemplate::query()->find($data['video_template_id']) : null;
            $post->fill([
                'sample_key' => $data['sample_key'],
                'hook' => $data['hook'],
                'topic_id' => $topicId,
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
            if ($data['outfit'] || $data['product_batch']) {
                $post->update(['status' => SocialPostStatus::Generating]);
            } else {
                $this->contentJobs->queueForPost($post);
            }
            if ($post->plan_item_id) {
                ContentPlanItem::query()->whereKey($post->plan_item_id)->update([
                    'outfit' => $data['outfit'] ? json_encode($data['outfit']) : null,
                    'product_batch' => $data['product_batch'] ? json_encode($data['product_batch']) : null,
                    'sample_key' => $data['sample_key'], 'hook' => $data['hook'], 'topic_id' => $topicId, 'images' => json_encode($data['images']),
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
