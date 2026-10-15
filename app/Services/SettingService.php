<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Đọc cấu hình hệ thống: bảng settings, nếu chưa có thì lấy config('ai90.defaults').
 */
class SettingService
{
    private ?array $values = null;

    public function get(string $key, mixed $default = null): mixed
    {
        $this->values ??= Setting::query()->pluck('value', 'key')->all();

        // Key có dấu chấm (generation.xxx) không đọc được bằng config("ai90.defaults.{$key}") -> lấy thẳng từ mảng
        return $this->values[$key] ?? config('ai90.defaults')[$key] ?? $default;
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function set(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => (string) $value]);
        $this->values = null;
    }
}
