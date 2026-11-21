<?php

namespace App\Enums;

enum OutputStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    // Chỉ việc worker (worker_jobs): việc bị thay bằng việc mới (chủ shop "Tạo lại", sửa chữ...)
    case Superseded = 'superseded';

    public function isFinal(): bool
    {
        return $this === self::Completed || $this === self::Failed || $this === self::Superseded;
    }
}
