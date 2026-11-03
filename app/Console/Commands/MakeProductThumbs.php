<?php

namespace App\Console\Commands;

use App\Exceptions\BusinessException;
use App\Models\ProductImage;
use App\Services\MediaStorage;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * Tạo bù thumbnail (rộng ProductImage::THUMB_WIDTH, cao theo tỷ lệ) cho ảnh sản phẩm upload trước khi có thumbnail.
 * Chạy lại an toàn: chỉ xử lý ảnh chưa có thumb_path. Ảnh lỗi bỏ qua, vẫn hiển thị bằng ảnh gốc.
 */
class MakeProductThumbs extends Command
{
    protected $signature = 'products:make-thumbs';

    protected $description = 'Tạo thumbnail cho ảnh sản phẩm chưa có thumbnail';

    public function handle(MediaStorage $media): int
    {
        $done = 0;
        $failed = 0;

        ProductImage::query()
            ->whereNull('thumb_path')
            ->with('product:id,user_id')
            ->chunkById(100, function ($images) use ($media, &$done, &$failed) {
                foreach ($images as $image) {
                    $tmp = tempnam(sys_get_temp_dir(), 'pthumb');
                    try {
                        file_put_contents($tmp, $media->get($image->path));
                        $file = new UploadedFile($tmp, basename($image->path), null, null, true);
                        $directory = 'products/'.($image->product?->user_id ?? 'orphan');
                        $thumbPath = $media->storeOptimizedImage($file, $directory, [ProductImage::THUMB_WIDTH], true)[0];
                        $image->update(['thumb_path' => $thumbPath]);
                        $done++;
                    } catch (Throwable $e) {
                        $this->warn("Ảnh #{$image->id} ({$image->path}): {$e->getMessage()}");
                        $failed++;
                    } finally {
                        @unlink($tmp);
                    }
                }
            });

        $this->info("Đã tạo {$done} thumbnail, lỗi {$failed} ảnh.");

        return self::SUCCESS;
    }
}
