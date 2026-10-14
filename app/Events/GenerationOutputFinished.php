<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Một ảnh vừa chốt (thành công hoặc thất bại). Phát sau khi transaction commit để listener đọc được trạng thái mới nhất
 * của mọi ảnh (ảnh cuối cùng commit luôn thấy đủ các ảnh còn lại).
 */
class GenerationOutputFinished implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $outputId) {}
}
