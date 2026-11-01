<?php

namespace App\Models;

use App\Enums\OutputStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GenerationOutput extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => OutputStatus::class,
            'lease_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
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

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }
}
