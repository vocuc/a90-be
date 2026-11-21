<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MỌI việc giao worker, 1 bảng phân biệt bằng type (a90-fe note/gop-viec-worker.txt, chốt 2026-11-21).
 * type = loại worker nhận: image (ảnh Creative), outfit (ảnh lô phối đồ), content (dựng bài ảnh), publish (đăng bài).
 * Worker nhận / trả việc qua 1 API chung /api/v1/worker-jobs. Model con theo type (ImageJob, ContentJob,
 * SocialPublishJob) giữ hằng số / cast riêng của từng loại, cùng đọc bảng này.
 */
class WorkerJob extends Model
{
    public const TYPE_IMAGE = 'image';

    public const TYPE_OUTFIT = 'outfit';

    public const TYPE_CONTENT = 'content';

    public const TYPE_PUBLISH = 'publish';

    /** Xây dựng chủ đề tự động cho 1 kênh (worker lập chủ đề). */
    public const TYPE_TOPIC = 'topic';

    public const TYPES = [self::TYPE_IMAGE, self::TYPE_OUTFIT, self::TYPE_CONTENT, self::TYPE_PUBLISH, self::TYPE_TOPIC];

    public const QUEUED = 'queued';

    public const PROCESSING = 'processing';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    /** Việc bị thay (sửa chữ, làm lại, chủ shop bấm "Tạo lại"): worker gửi kết quả -> 409 JOB_NOT_ASSIGNED. */
    public const SUPERSEDED = 'superseded';

    /** Model con: type của model (null = mọi type). */
    protected const JOB_TYPES = null;

    protected $table = 'worker_jobs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'platforms' => 'array',
            'lease_expires_at' => 'datetime',
            'claimed_at' => 'datetime',
            'finished_at' => 'datetime',
            'payload' => 'array',
            'result' => 'array',
        ];
    }

    protected static function booted(): void
    {
        if (static::JOB_TYPES === null) {
            return;
        }
        static::addGlobalScope('type', fn (Builder $q) => $q->whereIn('worker_jobs.type', static::JOB_TYPES));
        static::creating(function (WorkerJob $job) {
            $job->type ??= static::JOB_TYPES[0];
        });
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class, 'social_post_id');
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function output(): BelongsTo
    {
        return $this->belongsTo(GenerationOutput::class, 'generation_output_id');
    }
}
