<?php

namespace App\Services\Channel;

use App\Models\Channel;
use App\Models\ChannelTopic;
use App\Models\ContentPlanItem;
use App\Support\ProductTags;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Chủ đề kênh (a90-fe note/xay-kenh-tiktok/chu-de-va-tag-san-pham.txt mục 4): mọi bài lệnh /lap-ke-hoach gửi phải có
 * chủ đề (topic_id có sẵn hoặc topic {name, tags} mới, A51 tạo). Cùng chủ đề + cùng loại bài không lặp trong
 * ChannelTopic::REPEAT_DAYS ngày.
 */
class ChannelTopicService
{
    /**
     * Kiểm tra chủ đề của 1 bài (không ghi DB). Trả {id: ?int (null = chủ đề mới), name, tags} hoặc null nếu lỗi.
     */
    public function check(Channel $channel, array $raw, string $sampleKey, CarbonImmutable $at, array &$errors,
        ?int $exceptPostId = null, ?int $exceptItemId = null): ?array
    {
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
                [$tags, $tagErrors] = ProductTags::cleanFilter(is_array($raw['topic']['tags'] ?? null) ? $raw['topic']['tags'] : []);
                if ($tagErrors) {
                    array_push($errors, ...array_map(fn ($e) => "topic.tags: {$e}", $tagErrors));

                    return null;
                }

                return ['id' => null, 'name' => $name, 'tags' => $tags ?: null];
            }
        } else {
            $errors[] = 'Bài cần chủ đề: topic_id (chủ đề có sẵn) hoặc topic {name, tags} (chủ đề mới).';

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

        return ['id' => $topic->id, 'name' => $topic->name, 'tags' => $topic->tags];
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

    /** Id chủ đề đã kiểm tra (tạo mới nếu chưa có, nguồn ai). */
    public function resolve(Channel $channel, array $topic): int
    {
        if ($topic['id']) {
            return $topic['id'];
        }

        return $channel->topics()->firstOrCreate(['name' => $topic['name']], [
            'tags' => $topic['tags'],
            'source' => ProductTags::SOURCE_AI,
            'status' => ChannelTopic::ACTIVE,
        ])->id;
    }

    /**
     * Chủ đề của kênh kèm số bài theo loại bài + lần dùng gần nhất (bài đăng còn lại + bài kế hoạch chưa duyệt).
     *
     * @return array<int, array{id: int, name: string, tags: ?array, source: string, status: string, posts: array<string, int>, last_used: array<string, string>}>
     */
    public function listFor(Channel $channel, bool $activeOnly = false): array
    {
        $topics = $channel->topics()->when($activeOnly, fn ($q) => $q->where('status', ChannelTopic::ACTIVE))->orderBy('name')->get();
        $posts = DB::table('social_posts')->where('channel_id', $channel->id)->whereNotNull('topic_id')
            ->groupBy('topic_id', 'sample_key')
            ->selectRaw('topic_id, sample_key, COUNT(*) AS total, MAX(scheduled_at) AS last_at')->get();
        $items = DB::table('content_plan_items')->join('content_plans', 'content_plans.id', '=', 'content_plan_items.plan_id')
            ->where('content_plans.channel_id', $channel->id)->where('content_plan_items.status', ContentPlanItem::DRAFT)
            ->whereNotNull('content_plan_items.topic_id')
            ->groupBy('content_plan_items.topic_id', 'content_plan_items.sample_key')
            ->selectRaw('content_plan_items.topic_id, content_plan_items.sample_key, COUNT(*) AS total, MAX(content_plan_items.scheduled_at) AS last_at')->get();
        $rows = $posts->concat($items)->groupBy('topic_id');

        return $topics->map(function (ChannelTopic $t) use ($rows) {
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
                'tags' => $t->tags,
                'source' => $t->source,
                'status' => $t->status,
                'posts' => (object) $counts,
                'last_used' => (object) $last,
            ];
        })->values()->all();
    }
}
