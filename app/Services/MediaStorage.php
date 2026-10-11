<?php

namespace App\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Lưu và đọc ảnh trên disk cấu hình bởi MEDIA_DISK (S3 trên production).
 * Mọi ảnh đều private, frontend chỉ nhận signed URL có thời hạn.
 */
class MediaStorage
{
    public function __construct(private ImageOptimizer $optimizer) {}

    public function disk(): Filesystem
    {
        return Storage::disk(config('ai90.media_disk'));
    }

    public function storeUpload(UploadedFile $file, string $directory): string
    {
        $extension = $file->extension() ?: 'bin';
        $path = trim($directory, '/').'/'.Str::uuid().'.'.$extension;
        $this->disk()->put($path, $file->getContent());

        return $path;
    }

    /**
     * Lưu ảnh đã tối ưu, mỗi giới hạn cạnh dài một file (vd [1600, 600] = ảnh chính + thumbnail).
     *
     * @param  int[]  $maxSides
     * @return string[] đường dẫn cùng thứ tự với $maxSides
     */
    public function storeOptimizedImage(UploadedFile $file, string $directory, array $maxSides): array
    {
        $variants = $this->optimizer->optimize($file, $maxSides);
        $base = trim($directory, '/').'/'.Str::uuid();

        $paths = [];
        foreach ($variants as $i => $variant) {
            $path = $base.($i === 0 ? '' : '_'.$maxSides[$i]).'.'.$variant['extension'];
            $this->disk()->put($path, $variant['contents']);
            $paths[] = $path;
        }

        return $paths;
    }

    public function put(string $path, string $contents): void
    {
        $this->disk()->put($path, $contents);
    }

    public function get(string $path): string
    {
        return $this->disk()->get($path);
    }

    public function mimeType(string $path): string
    {
        return $this->disk()->mimeType($path) ?: 'application/octet-stream';
    }

    public function delete(?string $path): void
    {
        if ($path) {
            $this->disk()->delete($path);
        }
    }

    public function url(?string $path, ?string $downloadName = null): ?string
    {
        if (! $path) {
            return null;
        }

        $options = $downloadName
            ? ['ResponseContentDisposition' => 'attachment; filename="'.$downloadName.'"']
            : [];

        return $this->disk()->temporaryUrl(
            $path,
            now()->addMinutes(config('ai90.signed_url_ttl_minutes')),
            $options,
        );
    }

    /** Thư mục theo tháng để S3 lifecycle rule xóa ảnh sau 1 năm. */
    public static function datedDirectory(string $prefix): string
    {
        return $prefix.'/'.now()->format('Y/m');
    }
}
