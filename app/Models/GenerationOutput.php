<?php

namespace App\Models;

use App\Enums\OutputStatus;
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
        ];
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
