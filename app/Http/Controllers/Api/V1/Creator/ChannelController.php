<?php

namespace App\Http\Controllers\Api\V1\Creator;

use App\Enums\SocialPostStatus;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Hashtag;
use App\Models\ShopAiSetting;
use App\Models\SocialPost;
use App\Models\User;
use App\Models\Worker;
use App\Services\AuditLogger;
use App\Services\Social\SocialContentJobService;
use App\Support\PostSamples;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Kênh của shop (a90-fe note/xay-kenh-tiktok/dac-ta-giai-doan-1.txt mục 4.1, 5.1, 5.6, 6.1): tên, worker đăng bài
 * (1 worker chỉ thuộc 1 kênh), chân dung khách hàng, cấu hình kế hoạch, công tắc tự duyệt, cài đặt AI viết nội dung
 * (thay trang "AI viết nội dung" theo shop cũ). Kênh đầu tiên của shop chép cài đặt AI + kho hashtag cũ của shop.
 */
class ChannelController extends Controller
{
    /** Trạng thái bài còn chưa đăng xong: kênh còn bài này thì không xoá được. */
    private const UNFINISHED = [
        SocialPostStatus::Generating, SocialPostStatus::PendingReview, SocialPostStatus::Queued, SocialPostStatus::Publishing,
    ];

    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $channels = $request->user()->channels()->with('worker')->orderBy('id')->get();
        $upcoming = SocialPost::query()
            ->whereIn('channel_id', $channels->pluck('id'))
            ->whereIn('status', [SocialPostStatus::Generating, SocialPostStatus::Queued])
            ->where('scheduled_at', '>=', now())
            ->selectRaw('channel_id, count(*) as total')
            ->groupBy('channel_id')
            ->pluck('total', 'channel_id');

        return response()->json([
            'data' => $channels->map(fn (Channel $c) => [...$this->summary($c), 'upcoming_posts' => (int) ($upcoming[$c->id] ?? 0)]),
            'meta' => ['max' => Channel::MAX_PER_SHOP],
        ]);
    }

    public function show(Request $request, int $channel): JsonResponse
    {
        return $this->response($this->find($request, $channel));
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:'.Channel::NAME_MAX],
            'worker_id' => ['required', 'integer'],
        ], ['name.required' => 'Nhập tên kênh.', 'worker_id.required' => 'Chọn worker đăng bài.']);

        $channel = DB::transaction(function () use ($user, $data) {
            // Khoá theo shop để 2 request tạo kênh cùng lúc không vượt giới hạn / không cùng chép cài đặt cũ
            User::query()->lockForUpdate()->find($user->id);
            if ($user->channels()->count() >= Channel::MAX_PER_SHOP) {
                throw new BusinessException('CHANNEL_LIMIT', 'Mỗi shop tối đa '.Channel::MAX_PER_SHOP.' kênh.', 409);
            }
            $workerId = $this->resolveWorker($user, (int) $data['worker_id']);
            $first = ! $user->channels()->exists();
            $legacy = $first ? ShopAiSetting::query()->where('owner_id', $user->id)->first() : null;

            $channel = $user->channels()->create([
                'name' => trim($data['name']),
                'worker_id' => $workerId,
                ...Channel::aiDefaults(),
                ...($legacy ? [
                    'title_max' => $legacy->title_max,
                    'content_max' => $legacy->content_max,
                    'requirements' => mb_substr((string) $legacy->requirements, 0, Channel::REQUIREMENTS_MAX) ?: null,
                ] : []),
            ]);
            // Kênh đầu tiên nhận kho hashtag cũ của shop (a90-fe note/xay-kenh-tiktok/dac-ta-giai-doan-1.txt mục 4.10)
            if ($first) {
                $legacyTags = $user->shopHashtags()->pluck('name')->all();
                if ($legacyTags) {
                    $channel->hashtags()->sync(Hashtag::syncPivot($legacyTags));
                }
            }
            $this->audit->log('channel.create', $channel, ['worker_id' => $workerId, 'copied_legacy_ai' => (bool) $legacy], $user->id);

            return $channel;
        });

        return $this->response($channel->refresh())->setStatusCode(201);
    }

    /** Gửi phần nào sửa phần đó (tên, worker, cấu hình, công tắc, chân dung, cài đặt AI, kho hashtag). */
    public function update(Request $request, int $channel): JsonResponse
    {
        $channel = $this->find($request, $channel);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:'.Channel::NAME_MAX],
            'worker_id' => ['sometimes', 'required', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
            'plan_days' => ['sometimes', 'required', 'integer', 'min:1', 'max:'.Channel::PLAN_DAYS_MAX],
            'posts_per_day' => ['sometimes', 'required', 'integer', 'min:1', 'max:'.Channel::POSTS_PER_DAY_MAX],
            'time_slots' => ['sometimes', 'present', 'array', 'max:'.Channel::TIME_SLOTS_MAX],
            'time_slots.*.start' => ['required', 'date_format:H:i'],
            'time_slots.*.end' => ['required', 'date_format:H:i'],
            'auto_approve_lineup' => ['sometimes', 'boolean'],
            'auto_approve_templates' => ['sometimes', 'boolean'],
            'auto_approve_plan' => ['sometimes', 'boolean'],
            'persona' => ['sometimes', 'present', 'array'],
            'persona.*' => ['nullable', 'string', 'max:'.Channel::PERSONA_FIELD_MAX],
            'title_max' => ['sometimes', 'required', 'integer', 'min:'.Channel::TITLE_MAX_MIN, 'max:'.SocialPost::TITLE_MAX],
            'content_max' => ['sometimes', 'required', 'integer', 'min:'.Channel::CONTENT_MAX_MIN, 'max:'.SocialContentJobService::AI_CONTENT_MAX],
            'requirements' => ['sometimes', 'nullable', 'string', 'max:'.Channel::REQUIREMENTS_MAX],
            'hashtags' => ['sometimes', 'present', 'array', 'max:'.Channel::MAX_HASHTAGS],
            'hashtags.*' => ['required', 'string', 'max:50', 'regex:'.Hashtag::PATTERN, function (string $attribute, mixed $value, Closure $fail) {
                if (is_string($value) && Hashtag::slugFor($value) === '') {
                    $fail('Hashtag phải chứa chữ cái hoặc chữ số.');
                }
            }],
        ], [
            'time_slots.max' => 'Tối đa '.Channel::TIME_SLOTS_MAX.' khung giờ.',
            'time_slots.*.start.date_format' => 'Giờ bắt đầu dạng HH:MM.',
            'time_slots.*.end.date_format' => 'Giờ kết thúc dạng HH:MM.',
            'requirements.max' => 'Yêu cầu tối đa '.Channel::REQUIREMENTS_MAX.' ký tự.',
            'hashtags.max' => 'Tối đa '.Channel::MAX_HASHTAGS.' hashtag.',
            'hashtags.*.regex' => 'Hashtag chỉ gồm chữ, số và dấu gạch dưới.',
            'persona.*.max' => 'Mỗi mục chân dung tối đa '.Channel::PERSONA_FIELD_MAX.' ký tự.',
        ]);

        $user = $request->user();
        $fields = collect($data)->only([
            'name', 'is_active', 'plan_days', 'posts_per_day',
            'auto_approve_lineup', 'auto_approve_templates', 'auto_approve_plan', 'title_max', 'content_max',
        ])->all();
        if (isset($fields['name'])) {
            $fields['name'] = trim($fields['name']);
        }
        if (array_key_exists('requirements', $data)) {
            $fields['requirements'] = trim((string) $data['requirements']) ?: null;
        }
        if (array_key_exists('time_slots', $data)) {
            $fields['time_slots'] = self::normalizeSlots($data['time_slots']) ?: null;
        }
        if (array_key_exists('persona', $data)) {
            $fields['persona'] = Channel::normalizePersona($data['persona']);
        }

        DB::transaction(function () use ($user, $channel, $data, $fields) {
            if (array_key_exists('worker_id', $data)) {
                $fields['worker_id'] = $this->resolveWorker($user, (int) $data['worker_id'], $channel->id);
            }
            $channel->update($fields);
            if (array_key_exists('hashtags', $data)) {
                $channel->hashtags()->sync(Hashtag::syncPivot($data['hashtags']));
            }
            // Đổi worker: bài chưa được worker nhận chuyển sang worker mới của kênh
            if (isset($fields['worker_id'])) {
                $channel->posts()
                    ->whereIn('status', [SocialPostStatus::Generating, SocialPostStatus::PendingReview, SocialPostStatus::Queued])
                    ->update(['worker_id' => $fields['worker_id']]);
            }
        });
        $this->audit->log('channel.update', $channel, ['fields' => array_keys($data)], $user->id);

        return $this->response($channel->refresh());
    }

    public function destroy(Request $request, int $channel): JsonResponse
    {
        $channel = $this->find($request, $channel);
        if ($channel->posts()->whereIn('status', self::UNFINISHED)->exists()) {
            throw new BusinessException('CHANNEL_HAS_POSTS', 'Kênh còn bài chưa đăng, huỷ hoặc xoá các bài đó trước.', 409);
        }
        $channel->delete();
        $this->audit->log('channel.delete', null, ['channel_id' => $channel->id], $request->user()->id);

        return response()->json(null, 204);
    }

    /** Worker đăng bài được gắn cho shop: còn trống hay đã thuộc kênh nào (cho ô chọn worker). */
    public function workers(Request $request): JsonResponse
    {
        $user = $request->user();
        $owners = Channel::query()->whereNotNull('worker_id')->pluck('id', 'worker_id');
        $workers = Worker::query()->assignedTo($user->id, Worker::TYPE_PUBLISH)->orderBy('name')->get();

        return response()->json(['data' => $workers->map(fn (Worker $w) => [
            'id' => $w->id,
            'name' => $w->name,
            'channel_id' => $owners[$w->id] ?? null,
        ])->values()]);
    }

    public function postSamples(): JsonResponse
    {
        return response()->json([
            'data' => array_values(PostSamples::all()),
            'meta' => ['groups' => PostSamples::GROUPS],
        ]);
    }

    /**
     * Khung giờ: bỏ trùng, sắp theo giờ bắt đầu, kết thúc phải sau bắt đầu, không chồng nhau.
     *
     * @param  array<int, array{start: string, end: string}>  $slots
     * @return array<int, array{start: string, end: string}>
     */
    public static function normalizeSlots(array $slots): array
    {
        $slots = collect($slots)
            ->map(fn ($s) => ['start' => $s['start'], 'end' => $s['end']])
            ->unique(fn ($s) => $s['start'].'-'.$s['end'])
            ->sortBy('start')
            ->values();
        foreach ($slots as $i => $slot) {
            if ($slot['end'] <= $slot['start']) {
                throw ValidationException::withMessages(['time_slots' => "Khung {$slot['start']} - {$slot['end']}: giờ kết thúc phải sau giờ bắt đầu."]);
            }
            $prev = $slots[$i - 1] ?? null;
            if ($prev && $slot['start'] < $prev['end']) {
                throw ValidationException::withMessages(['time_slots' => "Khung {$prev['start']} - {$prev['end']} và {$slot['start']} - {$slot['end']} bị chồng nhau."]);
            }
        }

        return $slots->all();
    }

    /** Worker publish đang bật được gắn cho shop và chưa thuộc kênh khác ($exceptChannelId: kênh đang sửa). */
    private function resolveWorker(User $user, int $workerId, ?int $exceptChannelId = null): int
    {
        $worker = Worker::query()->assignedTo($user->id, Worker::TYPE_PUBLISH)->find($workerId)
            ?? throw ValidationException::withMessages(['worker_id' => 'Worker đăng bài không được gắn cho shop hoặc đang tắt.']);
        $taken = Channel::query()->where('worker_id', $worker->id)
            ->when($exceptChannelId, fn ($q) => $q->whereKeyNot($exceptChannelId))
            ->exists();
        if ($taken) {
            throw ValidationException::withMessages(['worker_id' => 'Worker "'.$worker->name.'" đã thuộc kênh khác.']);
        }

        return $worker->id;
    }

    private function find(Request $request, int $id): Channel
    {
        return $request->user()->channels()->with('worker')->find($id)
            ?? throw new BusinessException('NOT_FOUND', 'Không tìm thấy kênh.', 404);
    }

    private function summary(Channel $channel): array
    {
        return [
            'id' => $channel->id,
            'name' => $channel->name,
            'platform' => $channel->platform,
            'is_active' => $channel->is_active,
            'worker' => $channel->worker ? ['id' => $channel->worker->id, 'name' => $channel->worker->name] : null,
            'missing' => $channel->missing(),
            'created_at' => $channel->created_at,
        ];
    }

    private function response(Channel $channel): JsonResponse
    {
        $channel->loadMissing('worker');
        $productHashtags = Hashtag::query()
            ->whereHas('products', fn ($q) => $q->where('user_id', $channel->user_id))
            ->orderBy('name')
            ->pluck('name');

        return response()->json(['data' => [
            ...$this->summary($channel),
            // MySQL JSON tự sắp lại khoá: trả theo thứ tự cố định
            'persona' => (object) (Channel::normalizePersona($channel->persona ?? []) ?? []),
            'plan_days' => $channel->plan_days,
            'posts_per_day' => $channel->posts_per_day,
            'time_slots' => array_map(fn ($s) => ['start' => $s['start'], 'end' => $s['end']], $channel->time_slots ?? []),
            // % giờ đăng video theo tuyến (chỉ đọc, bỏ ô cấu hình 2026-11-22)
            'video_percent' => $channel->videoPercent(),
            'auto_approve_lineup' => $channel->auto_approve_lineup,
            'auto_approve_templates' => $channel->auto_approve_templates,
            'auto_approve_plan' => $channel->auto_approve_plan,
            'title_max' => $channel->title_max,
            'content_max' => $channel->content_max,
            'requirements' => (string) $channel->requirements,
            'hashtags' => $channel->hashtags()->pluck('name')->values(),
            // Hashtag các sản phẩm của shop, cho nút "Lấy hashtag từ sản phẩm"
            'product_hashtags' => $productHashtags->values(),
            'limits' => [
                'plan_days' => Channel::PLAN_DAYS_MAX,
                'posts_per_day' => Channel::POSTS_PER_DAY_MAX,
                'time_slots' => Channel::TIME_SLOTS_MAX,
                'hashtags' => Channel::MAX_HASHTAGS,
                'ai_hashtags' => config('ai90.social.ai_max_hashtags'),
                'requirements' => Channel::REQUIREMENTS_MAX,
                'persona_field' => Channel::PERSONA_FIELD_MAX,
                'persona_fields' => Channel::PERSONA_FIELDS,
                'title_max' => ['min' => Channel::TITLE_MAX_MIN, 'max' => SocialPost::TITLE_MAX],
                'content_max' => ['min' => Channel::CONTENT_MAX_MIN, 'max' => SocialContentJobService::AI_CONTENT_MAX],
            ],
        ]]);
    }
}
