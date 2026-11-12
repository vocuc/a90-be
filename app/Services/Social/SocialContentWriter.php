<?php

namespace App\Services\Social;

/** AI viết tiêu đề / nội dung / hashtag cho 1 bài đăng từ ảnh và thông tin mẫu. */
interface SocialContentWriter
{
    /**
     * $request: creatives (tên, mô tả, ngành hàng của các mẫu), hashtags (kho hashtag của shop, AI chỉ được chọn trong
     * đây; rỗng thì không chọn), samples (bài viết mẫu của shop để học giọng văn), requirements (yêu cầu bắt buộc của
     * shop, rỗng = không có), limits (số ký tự tối đa shop đặt), idea (ý chính trong kịch bản của bài kế hoạch, null = không có).
     *
     * @param  array{creatives: array<int, array{title: ?string, description: ?string, category: ?string}>, hashtags: string[], samples: array<int, array{title: string, content: string}>, requirements?: string, idea?: ?string, limits: array{title_max: int, content_max: int, hashtags_max: int}}  $request
     * @param  array<int, array{contents: string, mime: string}>  $images  ảnh đầu của bài, ảnh bìa trước
     * @return array{title: string, content: string, hashtags: string[]}|null null khi không viết được (lỗi API, từ chối, kết quả hỏng)
     */
    public function write(array $request, array $images): ?array;
}
