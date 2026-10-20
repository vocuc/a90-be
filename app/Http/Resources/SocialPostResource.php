<?php

namespace App\Http\Resources;

use App\Enums\OutputStatus;
use App\Enums\PlatformPublishStatus;
use App\Enums\PublishDriver;
use App\Models\ImageBatchImage;
use App\Models\SocialPost;
use App\Models\SocialPostImage;
use App\Services\ImageBatchService;
use App\Services\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Cần load: images.output, images.generation.creative, publishJobs (SocialPostResource::RELATIONS).
 *
 * @mixin SocialPost
 */
class SocialPostResource extends JsonResource
{
    public const RELATIONS = ['images.output', 'images.generation.creative', 'publishJobs'];

    /** Thông báo người dùng thấy ở nền tảng đăng lỗi; mã lỗi chi tiết chỉ admin xem (social_publish_jobs). */
    public const PLATFORM_FAILED_MESSAGE = 'Đăng thất bại, vui lòng thử lại.';

    public function toArray(Request $request): array
    {
        $images = $this->images->map(fn (SocialPostImage $image) => self::image($image))->values();

        return [
            'id' => $this->id,
            'status' => $this->status,
            'source' => $this->source,
            'image_batch_id' => $this->image_batch_id,
            'title' => $this->title,
            'content' => $this->content ?? '',
            'hashtags' => $this->hashtags ?? [],
            'image_count' => $images->count(),
            'cover_url' => $images->firstWhere('thumb_url', '!==', null)['thumb_url'] ?? null,
            'images' => $images,
            // Nền tảng đã chọn đăng: { tiktok: { status, post_url, error, finished_at }, ... }
            'platforms' => $this->platforms(),
            'last_error' => $this->last_error,
            'created_at' => $this->created_at,
            'published_at' => $this->published_at,
        ];
    }

    private function platforms(): object
    {
        // Job gần nhất của từng nền tảng
        $jobs = $this->publishJobs->sortBy('id')->keyBy(fn ($job) => $job->platform->value);
        $result = [];
        foreach ($this->platformStatuses() as $platform => $status) {
            $job = $jobs->get($platform);
            $api = $job?->driver === PublishDriver::Api;
            $result[$platform] = [
                'status' => $status,
                // worker | api (TikTok người dùng tự kết nối: "Đã gửi vào hộp thư")
                'driver' => $job?->driver?->value,
                'post_url' => $job?->post_url,
                // Đăng qua API lỗi do tài khoản TikTok của chính người dùng (hết lượt, mất kết nối...): hiện thông báo cụ thể
                'error' => $status === PlatformPublishStatus::Failed
                    ? ($api && $job->error_message ? $job->error_message : self::PLATFORM_FAILED_MESSAGE)
                    : null,
                'finished_at' => $job?->finished_at,
            ];
        }

        return (object) $result;
    }

    /** Một ảnh của bài / lô: liên kết + dữ liệu lấy từ output và generation.creative. */
    public static function image(SocialPostImage|ImageBatchImage $image): array
    {
        $output = $image->output;
        $creative = $image->generation?->creative;
        $completed = $output?->status === OutputStatus::Completed;
        $media = app(MediaStorage::class);

        return [
            'id' => $image->id,
            'position' => $image->position,
            'generation_id' => $image->generation_id,
            'output_id' => $image->output_id,
            'status' => $output?->status,
            'image_url' => $completed ? $media->url($output->image_path) : null,
            'thumb_url' => $completed ? $media->url($output->thumbPathOrOriginal()) : null,
            'error_message' => $output?->status === OutputStatus::Failed ? GenerationResource::errorMessage($output->error_code) : null,
            'creative' => $creative ? ImageBatchService::creativeSummary($creative) : null,
        ];
    }
}
