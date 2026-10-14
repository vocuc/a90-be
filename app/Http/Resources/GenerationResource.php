<?php

namespace App\Http\Resources;

use App\Enums\OutputStatus;
use App\Models\Generation;
use App\Services\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Generation */
class GenerationResource extends JsonResource
{
    /** Thông báo hiển thị cho người dùng theo mã lỗi (không lộ chi tiết nội bộ). */
    private const ERROR_MESSAGES = [
        'CONTENT_BLOCKED' => 'Ảnh hoặc nội dung vi phạm chính sách an toàn của AI.',
        'NO_IMAGE_RETURNED' => 'AI không tạo được ảnh. Credit đã được hoàn lại.',
        'TIMEOUT' => 'Quá thời gian xử lý. Credit đã được hoàn lại.',
    ];

    public static function errorMessage(?string $errorCode): ?string
    {
        return $errorCode
            ? (self::ERROR_MESSAGES[$errorCode] ?? 'Tạo ảnh thất bại. Credit đã được hoàn lại.')
            : null;
    }

    public function toArray(Request $request): array
    {
        $media = app(MediaStorage::class);

        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'creative' => $this->whenLoaded('creative', fn () => [
                'id' => $this->creative->id,
                'slug' => $this->creative->slug,
                'title' => $this->creative->title,
            ]),
            'parent_generation_id' => $this->parent_generation_id,
            'quantity' => $this->quantity,
            'aspect_ratio' => $this->aspect_ratio,
            'price_per_image' => $this->price_per_image,
            'total_charged' => $this->price_per_image * $this->quantity,
            'success_count' => $this->success_count,
            'failed_count' => $this->failed_count,
            // Ảnh đầu vào theo tên trường
            'input_images' => array_map(fn ($path) => $media->url($path), $this->input_paths ?? []),
            'outputs' => $this->whenLoaded('outputs', fn () => $this->outputs->map(fn ($output) => [
                'id' => $output->id,
                'position' => $output->position,
                'status' => $output->status,
                'image_url' => $output->status === OutputStatus::Completed ? $media->url($output->image_path) : null,
                'error_code' => $output->error_code,
                'error_message' => self::errorMessage($output->error_code),
                'retry_of_output_id' => $output->retry_of_output_id,
            ])->values()),
            'created_at' => $this->created_at,
            'completed_at' => $this->completed_at,
        ];
    }
}
