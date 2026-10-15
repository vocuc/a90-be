<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tài khoản TikTok của A51 (admin quản lý). Worker giữ tài khoản (đã đăng nhập sẵn trong trình duyệt) nhận job đăng bài
 * và lưu nháp vào tài khoản này. Người dùng không chọn tài khoản.
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
