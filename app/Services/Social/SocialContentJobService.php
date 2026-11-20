<?php

namespace App\Services\Social;

use App\Enums\OutputStatus;
use App\Enums\SocialPostStatus;
use App\Exceptions\BusinessException;
use App\Jobs\WriteSocialPostContent;
use App\Models\Hashtag;
use App\Models\Channel;
use App\Models\SocialContentJob;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\MediaStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AI viết tiêu đề / nội dung / hashtag bằng Claude API (note/ai-viet-noi-dung-bai-dang.txt ở repo frontend).
 * - Tạo bài bật AI: tạo 1 job / bài, queue job WriteSocialPostContent viết rồi bài sang pending_review.
 *   AI lỗi / job kẹt quá lâu: dùng nội dung mặc định.
 * - "Viết lại bằng AI" (rewrite): gọi thẳng trong request, trả nội dung cho người dùng xem (không lưu vào bài).
 * Cài đặt của shop (trang "AI viết nội dung"): AI chỉ chọn hashtag trong kho hashtag,
 * tối đa ai90.social.ai_max_hashtags; học giọng văn theo bài viết mẫu; tiêu đề / nội dung không quá số ký tự shop đặt.
 */
class SocialContentJobService
{
    /** Nội dung AI viết tối đa (shop đặt được nhỏ hơn): vừa mọi nền tảng (Instagram 2200 gồm cả dòng hashtag). */
    public const AI_CONTENT_MAX = 2000;

    public function __construct(
        private SocialContentWriter $writer,
        private MediaStorage $media,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('ai90.social.ai_content');
    }

    /** Giao queue job viết nội dung. Gọi trong transaction tạo bài. */
    public function queue(SocialPost $post): void
    {
        $job = SocialContentJob::query()->firstOrCreate(
            ['social_post_id' => $post->id],
            ['status' => OutputStatus::Queued],
        );
        WriteSocialPostContent::dispatch($job->id)->afterCommit();
    }

    /** Queue job: AI viết xong thì điền vào bài, không viết được thì dùng nội dung mặc định. */
    public function run(int $jobId): void
    {
        $job = SocialContentJob::query()->with('post')->find($jobId);
        if ($job?->status !== OutputStatus::Queued || ! $job->post) {
            return;
        }
        $job->update(['status' => OutputStatus::Processing, 'attempts' => $job->attempts + 1]);

        $content = $this->write($job->post);

        DB::transaction(function () use ($jobId, $content) {
            $job = SocialContentJob::query()->lockForUpdate()->find($jobId);
            if ($job?->status !== OutputStatus::Processing) {
                return;
            }
            $content
                ? $this->finish($job, $content, OutputStatus::Completed)
                : $this->fallback($job, 'AI_FAILED', 'AI không viết được nội dung.');
        });
    }

    /** Queue job lỗi bất ngờ / job kẹt: dùng nội dung mặc định để bài không kẹt ở generating. */
    public function failJob(int $jobId, string $errorMessage): void
    {
        DB::transaction(function () use ($jobId, $errorMessage) {
            $job = SocialContentJob::query()->lockForUpdate()->find($jobId);
            if (in_array($job?->status, [OutputStatus::Queued, OutputStatus::Processing], true)) {
                $this->fallback($job, 'AI_FAILED', $errorMessage);
            }
        });
    }

    /** Job chờ / chạy quá lâu (queue không chạy, tiến trình chết giữa chừng): nội dung mặc định. */
    public function failStuck(): int
    {
        $ids = SocialContentJob::query()
            ->whereIn('status', [OutputStatus::Queued, OutputStatus::Processing])
            ->where('updated_at', '<', now()->subMinutes(config('ai90.social.ai_stuck_minutes')))
            ->pluck('id');

        foreach ($ids as $id) {
            $this->failJob($id, 'Quá thời gian chờ AI viết nội dung.');
        }

        return $ids->count();
    }

    /**
     * "Viết lại bằng AI": trả nội dung mới, không lưu vào bài.
     *
     * @return array{title: string, content: string, hashtags: string[]}
     */
    public function rewrite(User $user, SocialPost $post): array
    {
        if (! self::enabled()) {
            throw new BusinessException('AI_CONTENT_DISABLED', 'Tính năng AI viết nội dung đang tạm tắt.', 409);
        }

        return $this->write($post)
            ?? throw new BusinessException('AI_CONTENT_FAILED', 'AI chưa viết được nội dung, vui lòng thử lại.', 503);
    }

    /**
     * Gọi AI cho 1 bài theo cài đặt của shop rồi chuẩn hoá kết quả: tiêu đề cắt theo số ký tự shop đặt, hashtag chỉ
     * giữ cái có trong kho. AI KHÔNG viết nội dung: content luôn rỗng (2026-11-19, bài ảnh / video chỉ tiêu đề + hashtag).
     *
     * @return array{title: string, content: string, hashtags: string[]}|null
     */
    public function write(SocialPost $post): ?array
    {
        $post->loadMissing(['images.output', 'images.generation.creative.category']);
        // Cài đặt AI theo kênh của bài (a90-fe note/xay-kenh-tiktok/dac-ta-giai-doan-1.txt mục 5.2). Bài chưa giao worker
        // (vừa tạo từ lô / ảnh có sẵn) chưa có kênh: dùng kênh đầu tiên của shop; shop chưa có kênh: mặc định, không hashtag.
        $channel = $post->channel
            ?? Channel::query()->where('user_id', $post->user_id)->orderBy('id')->first();
        $settings = $channel?->aiSettings() ?? [...Channel::aiDefaults(), 'requirements' => '', 'hashtags' => []];
        $pool = $settings['hashtags'];
        $titleMax = min($settings['title_max'], SocialPost::TITLE_MAX);
        $contentMax = min($settings['content_max'], self::AI_CONTENT_MAX);
        $maxHashtags = config('ai90.social.ai_max_hashtags');

        $creatives = $post->images
            ->map(fn ($image) => $image->generation?->creative)
            ->filter()
            ->unique('id')
            ->values()
            ->map(fn ($creative) => [
                'title' => $creative->title,
                'description' => $creative->description,
                'category' => $creative->category?->name,
            ]);

        $images = $post->images
            ->filter(fn ($image) => $image->isUsable())
            ->take(config('ai90.social.ai_max_images'))
            ->map(function ($image) {
                $path = $image->thumbPath();

                return ['contents' => $this->media->get($path), 'mime' => $this->media->mimeType($path)];
            })
            ->values()
            ->all();

        $result = $this->writer->write([
            'creatives' => $creatives->all(),
            'hashtags' => $pool,
            // Bài viết mẫu (học giọng văn) đã bỏ: giọng văn nằm trong requirements của kênh
            'samples' => [],
            'requirements' => (string) $settings['requirements'],
            'limits' => ['title_max' => $titleMax, 'content_max' => $contentMax, 'hashtags_max' => $maxHashtags],
        ], $images);
        if (! $result) {
            return null;
        }

        $allowed = collect($pool)->keyBy(fn ($name) => Hashtag::slugFor($name));
        $hashtags = collect(SocialPost::normalizeHashtags($result['hashtags']))
            ->map(fn ($name) => $allowed->get(Hashtag::slugFor($name)))
            ->filter()
            ->unique()
            ->take($maxHashtags)
            ->values()
            ->all();

        return [
            'title' => Str::limit(trim(preg_replace('/\s+/u', ' ', $result['title'])), $titleMax, ''),
            // AI chỉ viết tiêu đề + hashtag, nội dung để trống (nội dung dài làm TikTok co khung ảnh về 3:4)
            'content' => '',
            'hashtags' => $hashtags,
        ];
    }

    /** Nội dung mặc định từ các mẫu của bài; người dùng tự sửa ở bước duyệt. */
    private function fallback(SocialContentJob $job, string $errorCode, string $errorMessage): void
    {
        $job->forceFill(['error_code' => $errorCode, 'error_message' => Str::limit($errorMessage, 490)]);
        $this->finish($job, $job->post->defaultContent(), OutputStatus::Failed);
    }

    /** @param  array{title: ?string, content: string, hashtags: string[]}  $content */
    private function finish(SocialContentJob $job, array $content, OutputStatus $status): void
    {
        $job->update([
            'status' => $status,
            'finished_at' => now(),
            ...($status === OutputStatus::Completed ? ['error_code' => null, 'error_message' => null] : []),
        ]);

        $post = SocialPost::query()->lockForUpdate()->findOrFail($job->social_post_id);
        if ($post->status !== SocialPostStatus::Generating) {
            return;
        }

        $post->update([
            'title' => ($content['title'] ?? '') !== '' ? $content['title'] : SocialPost::DEFAULT_TITLE,
            'content' => $content['content'],
            'hashtags' => $content['hashtags'],
            // Bài ảnh chờ người dùng bấm đăng; bài video chờ tới giờ cho worker nhận
            'status' => $post->readyStatus(),
        ]);
    }
}
