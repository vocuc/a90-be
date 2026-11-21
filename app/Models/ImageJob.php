<?php

namespace App\Models;

/**
 * Việc tạo 1 ảnh (worker_jobs type image | outfit): 1 việc / 1 generation_output. Ảnh (đường dẫn, status cho giao diện)
 * vẫn ở generation_outputs; hàng đợi (worker, lượt, hạn) ở đây. Tạo tự động khi tạo output queued (GenerationOutput).
 */
class ImageJob extends WorkerJob
{
    protected const JOB_TYPES = [self::TYPE_IMAGE, self::TYPE_OUTFIT];
}
