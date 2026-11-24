<?php

namespace App\Services\Channel;

use App\Models\Channel;
use App\Models\ChannelTopic;
use App\Models\ChannelTopicCategory;
use App\Models\ContentPlanItem;
use App\Support\ProductTags;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Chủ đề kênh (a90-fe note/xay-kenh-tiktok/chu-de-va-tag-san-pham.txt mục 4): mọi bài lệnh /lap-ke-hoach gửi phải có
 * chủ đề CÓ SẴN của kênh (topic_id; chủ đề do worker lập chủ đề hoặc chủ shop tạo, worker-lap-chu-de.txt). Cùng chủ đề +
 * cùng loại bài không lặp trong ChannelTopic::REPEAT_DAYS ngày.
 */
class ChannelTopicService
{
    /**
     * Khoá trong $raw (chỉ server đặt): bài có chủ đề đã bị xoá, chủ shop sửa bài không chọn chủ đề -> giữ "không xác định".
     */
    public const NO_TOPIC = '_no_topic';

    /**
     * Kiểm tra chủ đề của 1 bài (không ghi DB). Trả {id: ?int (null = chủ đề mới), name, note} hoặc null nếu lỗi.
     * $raw[NO_TOPIC]: trả {id: null, name: null, note: null} = không xác định.
     */
    public function check(Channel $channel, array $raw, string $sampleKey, CarbonImmutable $at, array &$errors,
        ?int $exceptPostId = null, ?int $exceptItemId = null): ?array
    {
        if (! empty($raw[self::NO_TOPIC])) {
            return ['id' => null, 'name' => null, 'note' => null];
        }
        $topic = null;
        if (! empty($raw['topic_id'])) {
            $model = $channel->topics()->find((int) $raw['topic_id']);
            if (! $model) {
                $errors[] = "topic_id {$raw['topic_id']} không phải chủ đề của kênh.";

                return null;
            }
            $topic = $model;
        } elseif (is_array($raw['topic'] ?? null)) {
            $name = ChannelTopic::cleanName($raw['topic']['name'] ?? '');
            if ($name === '' || mb_strlen($name) > ChannelTopic::NAME_MAX) {
                $errors[] = 'topic.name bắt buộc, tối đa '.ChannelTopic::NAME_MAX.' ký tự (chủ đề cụ thể, vd "Đi chúc Tết").';

                return null;
            }
            $topic = $channel->topics()->where('name', $name)->first();
            if (! $topic) {
                // Chỉ dùng chủ đề CÓ SẴN của kênh (người dùng chốt 2026-11-21): chủ đề do worker lập chủ đề / chủ shop tạo
                $errors[] = "Chủ đề \"{$name}\" chưa có trong kênh. Chỉ dùng chủ đề có sẵn (topic_id); hết chủ đề dùng được thì nhờ chủ shop bấm \"Xây dựng chủ đề\" ở tab Chủ đề (TOPIC_NOT_FOUND).";

                return null;
            }
        } else {
            $errors[] = 'Bài cần chủ đề có sẵn của kênh: topic_id (hoặc topic {name} trùng tên chủ đề có sẵn).';

            return null;
        }

        if ($topic->status !== ChannelTopic::ACTIVE) {
            $errors[] = "Chủ đề \"{$topic->name}\" đang tạm tắt, chọn chủ đề khác.";

            return null;
        }
        if ($this->repeated($channel, $topic->id, $sampleKey, $at, $exceptPostId, $exceptItemId)) {
            $errors[] = "Chủ đề \"{$topic->name}\" đã có bài \"{$sampleKey}\" trong vòng ".ChannelTopic::REPEAT_DAYS.' ngày (TOPIC_REPEATED). Đổi chủ đề hoặc loại bài.';

            return null;
        }

        return ['id' => $topic->id, 'name' => $topic->name, 'note' => $topic->note];
    }

    /** Cùng chủ đề + cùng loại bài đã có bài đăng / bài kế hoạch chưa duyệt trong ± REPEAT_DAYS ngày quanh $at. */
    public function repeated(Channel $channel, int $topicId, string $sampleKey, CarbonImmutable $at, ?int $exceptPostId = null, ?int $exceptItemId = null): bool
    {
        $from = $at->subDays(ChannelTopic::REPEAT_DAYS);
        $to = $at->addDays(ChannelTopic::REPEAT_DAYS);
        $post = $channel->posts()->where('topic_id', $topicId)->where('sample_key', $sampleKey)
            ->whereBetween('scheduled_at', [$from, $to])
            ->when($exceptPostId, fn ($q) => $q->whereKeyNot($exceptPostId))
            ->exists();
        if ($post) {
            return true;
        }

        return ContentPlanItem::query()->where('topic_id', $topicId)->where('sample_key', $sampleKey)
            ->where('status', ContentPlanItem::DRAFT)
            ->whereHas('plan', fn ($q) => $q->where('channel_id', $channel->id))
            ->whereBetween('scheduled_at', [$from, $to])
            ->when($exceptItemId, fn ($q) => $q->whereKeyNot($exceptItemId))
            ->exists();
    }

    /** Id chủ đề đã kiểm tra (chủ đề luôn có sẵn; giữ nhánh tạo cho dữ liệu cũ). null = không xác định (chủ đề đã xoá). */
    public function resolve(Channel $channel, array $topic): ?int
    {
        if ($topic['id']) {
            return $topic['id'];
        }
        if ($topic['name'] === null) {
            return null;
        }

        return $channel->topics()->firstOrCreate(['name' => $topic['name']], [
            'note' => $topic['note'] ?? null,
            'source' => ProductTags::SOURCE_AI,
            'status' => ChannelTopic::ACTIVE,
        ])->id;
    }

    /**
     * Chủ đề của kênh kèm số bài theo loại bài + lần dùng gần nhất (bài đăng còn lại + bài kế hoạch chưa duyệt).
     *
     * @return array<int, array{id: int, name: string, note: ?string, source: string, status: string, posts: array<string, int>, last_used: array<string, string>}>
     */
    public function listFor(Channel $channel, bool $activeOnly = false): array
    {
        // Theo thứ tự nhóm (chủ đề chưa có nhóm = "Khác" ở cuối), trong nhóm theo tên
        $order = $this->categories($channel)->pluck('id')->flip();
        $names = $this->categories($channel)->pluck('name', 'id');
        $topics = $channel->topics()->when($activeOnly, fn ($q) => $q->where('status', ChannelTopic::ACTIVE))->orderBy('name')->get()
            ->sortBy(fn (ChannelTopic $t) => $order[$t->category_id] ?? $order->count(), SORT_REGULAR)->values();
        $posts = DB::table('social_posts')->where('channel_id', $channel->id)->whereNotNull('topic_id')
            ->groupBy('topic_id', 'sample_key')
            ->selectRaw('topic_id, sample_key, COUNT(*) AS total, MAX(scheduled_at) AS last_at')->get();
        $items = DB::table('content_plan_items')->join('content_plans', 'content_plans.id', '=', 'content_plan_items.plan_id')
            ->where('content_plans.channel_id', $channel->id)->where('content_plan_items.status', ContentPlanItem::DRAFT)
            ->whereNotNull('content_plan_items.topic_id')
            ->groupBy('content_plan_items.topic_id', 'content_plan_items.sample_key')
            ->selectRaw('content_plan_items.topic_id, content_plan_items.sample_key, COUNT(*) AS total, MAX(content_plan_items.scheduled_at) AS last_at')->get();
        $rows = $posts->concat($items)->groupBy('topic_id');

        return $topics->map(function (ChannelTopic $t) use ($rows, $names) {
            $counts = [];
            $last = [];
            foreach ($rows->get($t->id, collect()) as $r) {
                $counts[$r->sample_key] = ($counts[$r->sample_key] ?? 0) + (int) $r->total;
                $at = Str::substr((string) $r->last_at, 0, 10);
                $last[$r->sample_key] = max($last[$r->sample_key] ?? '', $at);
            }

            return [
                'id' => $t->id,
                'name' => $t->name,
                // Nhóm chủ đề (chủ shop quản lý, 2026-11-24): category_id null = "Khác"
                'category_id' => $t->category_id,
                'category' => $names[$t->category_id] ?? ChannelTopic::OTHER_CATEGORY,
                'note' => $t->note,
                'source' => $t->source,
                'status' => $t->status,
                'posts' => (object) $counts,
                'last_used' => (object) $last,
            ];
        })->values()->all();
    }

    /** Nhóm chủ đề của kênh theo thứ tự; kênh chưa có nhóm nào thì tạo sẵn ChannelTopic::DEFAULT_CATEGORIES. */
    public function categories(Channel $channel)
    {
        if (! $channel->topicCategories()->exists()) {
            foreach (ChannelTopic::DEFAULT_CATEGORIES as $i => $name) {
                $channel->topicCategories()->firstOrCreate(['name' => $name], ['position' => $i + 1]);
            }
        }

        return $channel->topicCategories()->get();
    }

    /** Nhóm cho tab Chủ đề / worker: [{id, name, position, topics}] theo thứ tự. */
    public function categoryList(Channel $channel): array
    {
        $counts = $channel->topics()->whereNotNull('category_id')->groupBy('category_id')->selectRaw('category_id, COUNT(*) AS n')
            ->pluck('n', 'category_id');

        return $this->categories($channel)->map(fn (ChannelTopicCategory $c) => [
            'id' => $c->id, 'name' => $c->name, 'position' => $c->position, 'topics' => (int) ($counts[$c->id] ?? 0),
        ])->values()->all();
    }

    /**
     * Nhóm cho chủ đề mới: id nhóm của kênh khớp $value (id hoặc tên, không phân biệt hoa thường); không khớp thì đoán theo
     * $text (tên nhóm mặc định, nếu kênh còn nhóm đó); không đoán được -> null ("Khác").
     */
    public function resolveCategory(Channel $channel, mixed $value, string $text): ?int
    {
        $categories = $this->categories($channel);
        if (is_numeric($value) && $categories->contains('id', (int) $value)) {
            return (int) $value;
        }
        $find = fn (?string $name) => $name === null ? null
            : $categories->first(fn (ChannelTopicCategory $c) => mb_strtolower($c->name) === mb_strtolower(trim($name)))?->id;

        return (is_string($value) ? $find($value) : null) ?? $find(ChannelTopic::guessCategory($text));
    }

    /** Số hook gần nhất mỗi chủ đề trả cho tab Chủ đề. */
    public const HOOKS_PER_TOPIC = 50;

    /**
     * Hook đã viết theo từng chủ đề (tab Chủ đề, người dùng yêu cầu 2026-11-21), mới trước: bài đăng của kênh + bài kế hoạch
     * chờ duyệt. KHÔNG gửi trong context /lap-ke-hoach (tối ưu token; lệnh đã có recent_posts).
     *
     * @return array<int, array<int, array{hook: string, sample_key: ?string, at: ?string, kind: string, status: ?string}>>  topic_id => hooks
     */
    public function hooksFor(Channel $channel): array
    {
        $posts = DB::table('social_posts')->where('channel_id', $channel->id)->whereNotNull('topic_id')->whereNotNull('hook')
            ->get(['topic_id', 'hook', 'sample_key', 'scheduled_at as at', 'status'])
            ->map(fn ($r) => [...(array) $r, 'kind' => 'post']);
        $items = DB::table('content_plan_items')->join('content_plans', 'content_plans.id', '=', 'content_plan_items.plan_id')
            ->where('content_plans.channel_id', $channel->id)->where('content_plan_items.status', ContentPlanItem::DRAFT)
            ->whereNotNull('content_plan_items.topic_id')
            ->get(['content_plan_items.topic_id', 'content_plan_items.hook', 'content_plan_items.sample_key', 'content_plan_items.scheduled_at as at'])
            ->map(fn ($r) => [...(array) $r, 'kind' => 'draft', 'status' => null]);

        return $posts->concat($items)->groupBy('topic_id')
            ->map(fn ($rows) => $rows->sortByDesc('at')->take(self::HOOKS_PER_TOPIC)
                ->map(fn ($r) => ['hook' => $r['hook'], 'sample_key' => $r['sample_key'],
                    // Cột DB lưu theo múi giờ app: trả ISO có múi giờ như các API khác
                    'at' => $r['at'] ? \Carbon\CarbonImmutable::parse($r['at'], config('app.timezone'))->toIso8601String() : null,
                    'kind' => $r['kind'], 'status' => $r['status']])
                ->values()->all())
            ->all();
    }
}
