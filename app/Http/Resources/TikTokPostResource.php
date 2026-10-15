<?php

namespace App\Http\Resources;

use App\Enums\OutputStatus;
use App\Models\ImageBatchImage;
use App\Models\TikTokPost;
use App\Models\TikTokPostImage;
use App\Services\ImageBatchService;
use App\Services\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Cần load: images.output, images.generation.creative (TikTokPostResource::RELATIONS).
 *
 * @mixin TikTokPost
 */
class TikTokPostResource extends JsonResource
{
    public const RELATIONS = ['images.output', 'images.generation.creative'];

    public function toArray(Request $request): array
    {
        $images = $this->images->map(fn (TikTokPostImage $image) => self::image($image))->values();

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
            'last_error' => $this->last_error,
            'created_at' => $this->created_at,
            'published_at' => $this->published_at,
        ];
    }

    /** Một ảnh của bài / lô: liên kết + dữ liệu lấy từ output và generation.creative. */
    public static function image(TikTokPostImage|ImageBatchImage $image): array
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
