<?php

namespace App\Models;

use App\Enums\SocialPlatform;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    public function creatives(): HasMany
    {
        return $this->hasMany(AiCreative::class, 'creator_id');
    }

    /** Kho hashtag của shop (chủ shop quản lý): AI viết nội dung bài đăng chỉ chọn hashtag trong kho này. */
    public function shopHashtags(): BelongsToMany
    {
        return $this->belongsToMany(Hashtag::class, 'shop_hashtag', 'owner_id')
            ->withPivot('sort_order')
            ->orderByPivot('sort_order');
    }

    /** Logo chèn vào ảnh: ảnh đại diện shop. */
    public function logoPath(): ?string
    {
        return $this->creatorProfile?->avatar_path;
    }
}
