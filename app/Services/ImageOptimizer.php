<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use GdImage;
use Illuminate\Http\UploadedFile;

/**
 * Giảm dung lượng ảnh upload: xoay theo EXIF, thu nhỏ, mã hóa lại sang WebP (mất luôn metadata/GPS).
 * Dùng GD có sẵn, không cần package ngoài.
 */
class ImageOptimizer
{
    /** Chặn ảnh quá nhiều điểm ảnh vì GD bung toàn bộ ảnh ra RAM (~5 byte/pixel). */
    private const MAX_PIXELS = 40_000_000;

    private const WEBP_QUALITY = 82;

    /**
     * Tạo một bản cho mỗi giới hạn cạnh dài trong $maxSides (không phóng to ảnh nhỏ).
     *
     * @param  int[]  $maxSides
     * @return array<int, array{contents: string, extension: string}> cùng thứ tự với $maxSides
     */
    public function optimize(UploadedFile $file, array $maxSides): array
    {
        $original = $file->getContent();
        $size = @getimagesizefromstring($original);
        if (! $size) {
            throw $this->invalid();
        }
        if ($size[0] * $size[1] > self::MAX_PIXELS) {
            throw new BusinessException('IMAGE_TOO_LARGE', 'Ảnh quá lớn, tối đa '.(self::MAX_PIXELS / 1_000_000).' megapixel.', 422);
        }

        $image = @imagecreatefromstring($original);
        if (! $image) {
            throw $this->invalid();
        }
        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        $rotated = $size[2] === IMAGETYPE_JPEG ? $this->applyExifOrientation($image, $file) : null;
        $image = $rotated ?? $image;

        $variants = [];
        foreach ($maxSides as $maxSide) {
            $resized = $this->fit($image, $maxSide);
            $contents = $this->encodeWebp($resized ?? $image);
            if ($resized) {
                imagedestroy($resized);
            }

            // Ảnh đã nhỏ và tối ưu sẵn: mã hóa lại có thể còn nặng hơn, khi đó giữ bản gốc.
            $untouched = $resized === null && $rotated === null;
            $variants[] = $untouched && strlen($contents) >= strlen($original)
                ? ['contents' => $original, 'extension' => $file->extension() ?: 'bin']
                : ['contents' => $contents, 'extension' => 'webp'];
        }
        imagedestroy($image);

        return $variants;
    }

    /** Trả về ảnh đã thu nhỏ, hoặc null nếu ảnh đã nằm trong giới hạn. */
    private function fit(GdImage $image, int $maxSide): ?GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        if (max($width, $height) <= $maxSide) {
            return null;
        }

        $ratio = $maxSide / max($width, $height);
        $newWidth = max(1, (int) round($width * $ratio));
        $newHeight = max(1, (int) round($height * $ratio));

        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $canvas;
    }

    /** Ảnh chụp điện thoại lưu chiều xoay trong EXIF; mã hóa lại sẽ mất EXIF nên phải xoay thật. */
    private function applyExifOrientation(GdImage $image, UploadedFile $file): ?GdImage
    {
        if (! function_exists('exif_read_data')) {
            return null;
        }

        $exif = @exif_read_data($file->getRealPath());
        $angle = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => 270, // imagerotate xoay ngược chiều kim đồng hồ
            8 => 90,
            default => 0,
        };
        if ($angle === 0) {
            return null;
        }

        $rotated = imagerotate($image, $angle, 0);
        if (! $rotated) {
            return null;
        }
        imagedestroy($image);
        imagealphablending($rotated, false);
        imagesavealpha($rotated, true);

        return $rotated;
    }

    private function encodeWebp(GdImage $image): string
    {
        ob_start();
        imagewebp($image, null, self::WEBP_QUALITY);

        return (string) ob_get_clean();
    }

    private function invalid(): BusinessException
    {
        return new BusinessException('INVALID_IMAGE', 'Không đọc được ảnh. Vui lòng chọn ảnh JPG, PNG hoặc WEBP khác.', 422);
    }
}
