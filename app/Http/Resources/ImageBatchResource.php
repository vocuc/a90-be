<?php

namespace App\Http\Resources;

use App\Enums\OutputStatus;
use App\Models\ImageBatch;
use App\Models\ImageBatchImage;
use App\Services\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Chi tiết lô: load RELATIONS (có "images"). Danh sách: query withSummary() (không có "images").
 *
 * @mixin ImageBatch
 */
class ImageBatchResource extends JsonResource
{
    public const RELATIONS = ['images.output', 'images.generation.creative', 'product.coverImage', 'group'];

    public function toArray(Request $request): array
    {
        if ($this->relationLoaded('images')) {
            $images = $this->images->map(fn (ImageBatchImage $image) => SocialPostResource::image($image))->values();
            $statuses = $images->pluck('status');
            $counts = [
                'image_count' => $images->count(),
                'generating_count' => $statuses->filter(fn ($s) => in_array($s, [OutputStatus::Queued, OutputStatus::Processing], true))->count(),
                'failed_count' => $statuses->filter(fn ($s) => $s === OutputStatus::Failed)->count(),
            ];
            $cover = $images->firstWhere('thumb_url', '!==', null)['thumb_url'] ?? null;
        } else {
            $counts = [
                'image_count' => (int) $this->images_count,
                'generating_count' => (int) $this->generating_count,
                'failed_count' => (int) $this->failed_count,
            ];
            $output = $this->coverImage?->output;
            $cover = $output ? app(MediaStorage::class)->url($output->thumbPathOrOriginal()) : null;
        }

        return [
            'id' => $this->id,
            'status' => $counts['generating_count'] > 0 ? 'generating' : 'completed',
            // Lô tạo tự động (note/auto-tao-lo.txt ở repo frontend): sản phẩm / nhóm null khi lô tạo tay hoặc đã xoá
            'source' => $this->source ?? ImageBatch::SOURCE_MANUAL,
            'product' => $this->product ? [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'cover_url' => app(MediaStorage::class)->url($this->product->coverImage?->path),
            ] : null,
            'group' => $this->group ? ['id' => $this->group->id, 'name' => $this->group->name] : null,
            ...$counts,
            'cover_url' => $cover,
            'post_count' => (int) ($this->posts_count ?? $this->posts()->count()),
            'images' => $this->when(isset($images), fn () => $images),
            'created_at' => $this->created_at,
        ];
    }
}
