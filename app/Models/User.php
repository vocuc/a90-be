<?php

namespace App\Models;

use App\Enums\PublishDriver;
use App\Enums\SocialPlatform;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $attributes = [
        'role' => 'user',
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'status' => UserStatus::class,
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function creatorProfile(): HasOne
    {
        return $this->hasOne(CreatorProfile::class);
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    public function creditWallet(): HasOne
    {
        return $this->hasOne(CreditWallet::class);
    }

    public function creatorWallet(): HasOne
    {
        return $this->hasOne(CreatorWallet::class);
    }

    /** Tài khoản đăng bài (tối đa 1 / nền tảng / driver): worker admin gắn, hoặc TikTok người dùng tự kết nối. */
    public function publishAccounts(): HasMany
    {
        return $this->hasMany(PublishAccount::class);
    }

    /** TikTok người dùng tự kết nối (OAuth, driver api). */
    public function tiktokConnection(): HasOne
    {
        return $this->hasOne(PublishAccount::class)
            ->where('platform', SocialPlatform::Tiktok)
            ->where('driver', PublishDriver::Api);
    }

    public function creatives(): HasMany
    {
        return $this->hasMany(AiCreative::class, 'creator_id');
    }

    /** Shop mà người này đang làm nhân viên (note/chia-se-tai-nguyen.txt ở repo frontend). */
    public function staffMembership(): HasOne
    {
        return $this->hasOne(ShopStaff::class);
    }

    /** Nhân viên của shop người này. */
    public function staff(): HasMany
    {
        return $this->hasMany(ShopStaff::class, 'owner_id');
    }

    /** Chủ shop khi người này là nhân viên, null khi không. */
    public function shopOwnerId(): ?int
    {
        return $this->staffMembership?->owner_id;
    }

    /** Chủ sản phẩm / nhóm creative người này dùng để tạo lô: chủ shop khi là nhân viên, không thì chính mình. */
    public function resourceOwnerId(): int
    {
        return $this->shopOwnerId() ?? $this->id;
    }

    /** Logo chèn vào ảnh: ảnh đại diện shop, nhân viên (không có shop) dùng logo tự tải lên. */
    public function logoPath(): ?string
    {
        return $this->creatorProfile?->avatar_path ?? ($this->shopOwnerId() ? $this->logo_path : null);
    }
}
