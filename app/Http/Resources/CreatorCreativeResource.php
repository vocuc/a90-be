<?php

namespace App\Http\Resources;

use App\Models\AiCreative;
use App\Services\GenerationService;
use App\Services\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Creative trong Creator Studio: chỉ chủ sở hữu thấy, có đầy đủ prompt và cấu hình.
 *
 * @mixin AiCreative
 */
class CreatorCreativeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $media = app(MediaStorage::class);
        $detailed = $this->relationLoaded('config');

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'description' => $this->description,
            'category_id' => $this->category_id,
            'model_id' => $this->model_id,
            'price_per_image' => $this->price_per_image,
            'status' => $this->status,
            'pause_reason' => $this->pause_reason,
            'is_hidden' => $this->hidden_at !== null,
            'usage_count' => $this->usage_count,
            'rating_avg' => (float) $this->rating_avg,
            'cover_url' => $media->url($this->images->first()?->path),
            'published_at' => $this->published_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            $this->mergeWhen($detailed, fn () => [
                'images' => $this->images->map(fn ($image) => [
                    'id' => $image->id,
                    'url' => $media->url($image->path),
                    'sort_order' => $image->sort_order,
                ])->values(),
                'config' => [
                    'system_prompt' => $this->config?->system_prompt,
                    'creative_prompt' => $this->config?->creative_prompt,
                    'negative_prompt' => $this->config?->negative_prompt,
                    'max_outputs' => $this->config?->max_outputs,
                    'allowed_aspect_ratios' => $this->config?->allowed_aspect_ratios,
                    'default_aspect_ratio' => $this->config?->default_aspect_ratio,
                    'extra_params' => $this->config?->extra_params ?? (object) [],
                ],
                'available_aspect_ratios' => GenerationService::allowedAspectRatios($this->resource),
            ]),
        ];
    }
}
