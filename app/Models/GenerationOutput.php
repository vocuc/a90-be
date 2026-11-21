<?php

namespace App\Models;

use App\Enums\OutputStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

/**
 * 1 ẢNH của lượt tạo (đường dẫn + status cho giao diện). Hàng đợi giao worker (worker, lượt, hạn) nằm ở worker_jobs
 * (ImageJob, a90-fe note/gop-viec-worker.txt): output tạo ra ở trạng thái queued tự sinh 1 việc image / outfit.
 */
class GenerationOutput extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => OutputStatus::class,
            'finished_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (GenerationOutput $output) {
            if ($output->status !== OutputStatus::Queued) {
                return;
            }
            $generation = $output->generation()->first(['id', 'user_id', 'worker_id', 'kind']);
            ImageJob::query()->create([
                'type' => $generation?->isOutfit() ? WorkerJob::TYPE_OUTFIT : WorkerJob::TYPE_IMAGE,
                'user_id' => $generation?->user_id,
                'preferred_worker_id' => $generation?->worker_id,
                'generation_output_id' => $output->id,
                'status' => WorkerJob::QUEUED,
            ]);
        });
    }

    /** Ảnh dùng được: đã tạo xong, có file và chưa bị người dùng tắt (disabled_at). */
    public function isUsable(): bool
    {
        return $this->status === OutputStatus::Completed && $this->image_path && ! $this->disabled_at;
    }

    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('status', OutputStatus::Completed)->whereNotNull('image_path')->whereNull('disabled_at');
    }

    /** Ảnh tạo trước khi có thumbnail thì dùng lại ảnh gốc. */
    public function thumbPathOrOriginal(): ?string
    {
        return $this->thumb_path ?? $this->image_path;
    }

    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }

    /** Việc tạo ảnh này (worker_jobs). */
    public function job(): HasOne
    {
        return $this->hasOne(ImageJob::class, 'generation_output_id')->latestOfMany();
    }

    /** Worker đang / đã tạo ảnh (qua việc). */
    public function worker(): HasOneThrough
    {
        return $this->hasOneThrough(Worker::class, ImageJob::class, 'generation_output_id', 'id', 'id', 'worker_id');
    }
}
