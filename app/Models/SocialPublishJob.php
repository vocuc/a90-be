<?php

namespace App\Models;

use App\Enums\OutputStatus;
use App\Enums\SocialPlatform;

/**
 * Một lần worker nhận bài để đăng (worker_jobs type publish; note/gop-luong-dang-bai.txt ở repo frontend), nhận / trả
 * qua /api/v1/worker-jobs. 1 việc / lần nhận, chứa mọi nền tảng bài cần đăng (platforms); worker báo kết quả 1 lần.
 * Việc là lịch sử từng lần thử; trạng thái hiện tại của nền tảng nằm ở social_posts.{platform}_status. Không có hạn:
 * worker im lặng thì chủ shop bấm "Tạo lại" (việc cũ status superseded). Việc tạo ra là processing luôn.
 */
class SocialPublishJob extends WorkerJob
{
    protected const JOB_TYPES = [self::TYPE_PUBLISH];

    protected function casts(): array
    {
        return [...parent::casts(), 'status' => OutputStatus::class];
    }

    /** @return SocialPlatform[] */
    public function platformEnums(): array
    {
        return array_map(fn (string $value) => SocialPlatform::from($value), $this->platforms ?? []);
    }
}
