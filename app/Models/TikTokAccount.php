<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tài khoản TikTok do admin quản lý: gắn 1 tài khoản A51 (user_id) với 1 worker (máy đã đăng nhập sẵn tài khoản TikTok).
 * Worker chỉ nhận job đăng bài của tài khoản A51 được gắn và lưu nháp vào tài khoản TikTok này.
 */
class TikTokAccount extends Model
{
    protected $table = 'tiktok_accounts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** Tài khoản A51 được đăng bài qua tài khoản TikTok này. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    /** Bài đã lưu nháp / đang đăng vào tài khoản này. */
    public function posts(): HasMany
    {
        return $this->hasMany(TikTokPost::class, 'tiktok_account_id');
    }

    public function publishJobs(): HasMany
    {
        return $this->hasMany(TikTokPublishJob::class, 'tiktok_account_id');
    }
}
