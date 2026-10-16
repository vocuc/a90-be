<?php

namespace App\Models;

use App\Enums\SocialPlatform;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tài khoản đăng bài do admin quản lý: gắn 1 tài khoản A51 (user_id) với 1 worker (máy đã đăng nhập sẵn tài khoản
 * TikTok / Facebook / Instagram). Mỗi tài khoản A51 có tối đa 1 tài khoản / nền tảng, mỗi worker giữ 1 tài khoản.
 * Worker chỉ nhận job của nền tảng và tài khoản A51 được gắn.
 */
class PublishAccount extends Model
{
    protected $table = 'publish_accounts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'platform' => SocialPlatform::class,
            'is_active' => 'boolean',
        ];
    }

    /** Tài khoản A51 được đăng bài qua tài khoản này. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function publishJobs(): HasMany
    {
        return $this->hasMany(SocialPublishJob::class, 'publish_account_id');
    }
}
