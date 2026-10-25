<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Nhân viên của shop (note/chia-se-tai-nguyen.txt ở repo frontend): owner_id = chủ shop, user_id = nhân viên.
 * Mỗi người chỉ thuộc một shop (unique user_id); nhân viên không được có shop riêng.
 */
class ShopStaff extends Model
{
    protected $table = 'shop_staff';

    protected $guarded = ['id'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
