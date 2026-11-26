<?php

namespace App\Http\Resources;

use App\Models\AiCreative;
use App\Services\CreativeTemplate;
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
            'hashtags' => HashtagResource::collection($this->whenLoaded('hashtags')),
            'status' => $this->status,
            'is_public' => $this->is_public,
            'is_hidden' => $this->hidden_at !== null,
            'usage_count' => $this->usage_count,
            'rating_avg' => (float) $this->rating_avg,
            // Chi tiết lấy từ config; danh sách lấy từ cột subquery default_aspect_ratio (CreativeController::index)
            'default_aspect_ratio' => $detailed ? $this->config?->default_aspect_ratio : $this->resource->getAttributes()['default_aspect_ratio'] ?? null,
            // Danh sách: ảnh mới nhất trong lịch sử tạo của chính tài khoản (cột subquery latest_output_path ở CreativeController::index),
            // không có thì dùng ảnh bìa của Creative
            'cover_url' => $media->url(($this->resource->getAttributes()['latest_output_path'] ?? null) ?: $this->images->first()?->thumbPathOrOriginal()),
            // Nhóm tạo lô tự động đang chứa Creative (chỉ có ở danh sách)
            'group' => $this->whenLoaded('groups', fn () => ($group = $this->groups->first()) ? ['id' => $group->id, 'name' => $group->name, 'channel' => $group->relationLoaded('channel') && $group->channel ? ['id' => $group->channel->id, 'name' => $group->channel->name] : null] : null),
            'published_at' => $this->published_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            $this->mergeWhen($detailed, fn () => [
                'video_url' => $this->video_url,
                'images' => $this->images->map(fn ($image) => [
                    'id' => $image->id,
                    'url' => $media->url($image->path),
                    'thumb_url' => $media->url($image->thumbPathOrOriginal()),
                    'sort_order' => $image->sort_order,
                ])->values(),
                'config' => [
                    'input_schema' => $this->inputSchema($media),
                    // Prompt riêng của Creative để sửa; prompt cuối (đã gộp Template) ở full_prompt_template
                    'prompt_template' => $this->config?->ownPrompt(),
                    'prompt_template_id' => $this->config?->prompt_template_id,
                    'full_prompt_template' => $this->config?->prompt_template,
                    'max_outputs' => $this->config?->max_outputs,
                    'default_aspect_ratio' => $this->config?->default_aspect_ratio,
                ],
                'available_aspect_ratios' => GenerationService::allowedAspectRatios($this->resource),
            ]),
        ];
    }

    /** Nguyên liệu ẩn kèm link ảnh (asset_url) để Creator xem lại. */
    private function inputSchema(MediaStorage $media): ?array
    {
        $fields = $this->config?->input_schema;
        if ($fields === null) {
            return null;
        }

        $paths = $this->assets->pluck('path', 'id');

        return array_map(fn (array $field) => $field['type'] === CreativeTemplate::HIDDEN_IMAGE
            ? [...$field, 'asset_url' => $media->url($paths[$field['asset_id']] ?? null)]
            : $field, $fields);
    }
}
