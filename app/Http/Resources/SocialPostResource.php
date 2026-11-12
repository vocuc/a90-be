<?php

namespace App\Http\Resources;

use App\Enums\OutputStatus;
use App\Enums\PlatformPublishStatus;
use App\Models\ImageBatchImage;
use App\Models\SocialPost;
use App\Models\SocialPostImage;
use App\Services\ImageBatchService;
use App\Services\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Cần load: images.output, images.generation.creative, publishJobs, worker, product (SocialPostResource::RELATIONS).
 *
 * @mixin SocialPost
 */
class SocialPostResource extends JsonResource
{
    public const RELATIONS = ['images.output', 'images.generation.creative', 'publishJobs', 'worker', 'product', 'planContext'];

    /** Thông báo người dùng thấy ở nền tảng đăng lỗi; mã lỗi chi tiết chỉ admin xem (social_publish_jobs). */
    public const PLATFORM_FAILED_MESSAGE = 'Đăng thất bại, vui lòng thử lại.';

    public function toArray(Request $request): array
    {
        $images = $this->images->map(fn (SocialPostImage $image) => self::image($image))->values();

        return [
            'id' => $this->id,
            // photo | video (note/lap-lich-worker.txt ở repo frontend)
            'type' => $this->type,
            'status' => $this->status,
            'source' => $this->source,
            // Worker được giao đăng bài, giờ đăng, nền tảng đã chọn (note/gop-luong-dang-bai.txt ở repo frontend)
            'worker' => $this->worker ? ['id' => $this->worker->id, 'name' => $this->worker->name] : null,
            'scheduled_at' => $this->scheduled_at,
            'selected_platforms' => $this->platforms ?? [],
            // Bài video: worker dựng + đăng, template (bản chụp lúc chọn), sản phẩm lấy ảnh, giờ đăng. Bài ảnh: null
            'video' => $this->isVideo() ? [
                'worker' => $this->worker ? ['id' => $this->worker->id, 'name' => $this->worker->name] : null,
                'template' => [
                    'id' => $this->video_template_id,
                    'name' => $this->video_template_name,
                    'video_url' => $this->video_template_url,
                    'image_count' => $this->video_image_count,
                ],
                'product' => $this->product ? ['id' => $this->product->id, 'name' => $this->product->name] : null,
                'scheduled_at' => $this->scheduled_at,
            ] : null,
            // Bài kế hoạch (source plan): kịch bản { y_chinh, items: [câu lệnh từng ảnh] } + bối cảnh + sản phẩm. Bài khác: null
            'plan' => $this->isPlan() ? [
                'script' => $this->script,
                'context' => $this->planContext ? ['id' => $this->planContext->id, 'name' => $this->planContext->name] : null,
                'product' => $this->product ? ['id' => $this->product->id, 'name' => $this->product->name] : null,
            ] : null,
            'image_batch_id' => $this->image_batch_id,
            // Tạo bài có bật AI viết nội dung (generating = AI đang viết)
            'ai_content' => (bool) $this->ai_content,
            'title' => $this->title,
            'content' => $this->content ?? '',
            'hashtags' => $this->hashtags ?? [],
            'image_count' => $images->count(),
            'cover_url' => $images->firstWhere('thumb_url', '!==', null)['thumb_url'] ?? null,
            'images' => $images,
            // Trạng thái từng nền tảng đã bấm đăng: { tiktok: { status, post_url, error, finished_at }, ... }
            'platforms' => $this->platforms(),
            'last_error' => $this->last_error,
            'created_at' => $this->created_at,
            'published_at' => $this->published_at,
        ];
    }

    private function platforms(): object
    {
        $jobs = $this->publishJobs->sortBy('id');
        $result = [];
        foreach ($this->platformStatuses() as $platform => $status) {
            // Job gần nhất có nền tảng này (1 job / lần worker nhận bài, chứa mọi nền tảng)
            $job = $jobs->filter(fn ($j) => in_array($platform, $j->platforms ?? [], true))->last();
            $result[$platform] = [
                'status' => $status,
                'post_url' => $job?->status === OutputStatus::Completed ? $job->post_url : null,
                'error' => $status === PlatformPublishStatus::Failed ? self::PLATFORM_FAILED_MESSAGE : null,
                'finished_at' => $job?->finished_at,
            ];
        }

        return (object) $result;
    }

    /** Một ảnh của bài / lô: liên kết + dữ liệu lấy từ output và generation.creative. */
    public static function image(SocialPostImage|ImageBatchImage $image): array
    {
        if ($image instanceof SocialPostImage && $image->isUploaded()) {
            return self::uploadedImage($image);
        }

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
            // Người dùng đã tắt ảnh: vẫn hiển thị trong lô / bài, nhưng không dùng để đăng hay gửi worker
            'disabled' => (bool) $output?->disabled_at,
            'image_url' => $completed ? $media->url($output->image_path) : null,
            'thumb_url' => $completed ? $media->url($output->thumbPathOrOriginal()) : null,
            'error_message' => $output?->status === OutputStatus::Failed ? GenerationResource::errorMessage($output->error_code) : null,
            'creative' => $creative ? ImageBatchService::creativeSummary($creative) : null,
        ];
    }

    /** Ảnh upload thẳng (lookbook): cùng dạng với ảnh output, luôn completed, không có generation / creative. */
    private static function uploadedImage(SocialPostImage $image): array
    {
        $media = app(MediaStorage::class);

        return [
            'id' => $image->id,
            'position' => $image->position,
            'generation_id' => null,
            'output_id' => null,
            'status' => OutputStatus::Completed,
            'disabled' => false,
            'image_url' => $media->url($image->imagePath()),
            'thumb_url' => $media->url($image->thumbPath()),
            'error_message' => null,
            'creative' => null,
        ];
    }
}
