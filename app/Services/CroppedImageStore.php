<?php

namespace App\Services;

use App\Enums\OutputStatus;
use App\Exceptions\BusinessException;
use App\Models\GenerationOutput;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Ghi ảnh đã cắt / tải lên thay ảnh cũ (dùng chung cho "Cắt ảnh" ở lô và ở bài đăng).
 * Lưu file mới (ảnh + thumbnail) trước, ngoài transaction, để không giữ khoá trong lúc upload lên S3
 * (như WorkerJobService::complete); lỗi trong transaction thì xoá file mới, xong thì xoá file cũ.
 */
class CroppedImageStore
{
    public function __construct(private MediaStorage $media) {}

    /**
     * $apply(array $file): chạy trong transaction, ghi file mới vào bản ghi và trả các đường dẫn cũ cần xoá.
     * $file = ['image_path', 'thumb_path', 'mime', 'size_bytes'].
     *
     * @param  Closure(array): array<int, ?string>  $apply
     */
    public function replace(UploadedFile $file, string $directoryPrefix, Closure $apply): void
    {
        $directory = MediaStorage::datedDirectory($directoryPrefix);
        $path = $this->media->storeUpload($file, $directory);
        try {
            $thumbPath = $this->media->storeOptimizedImage($file, $directory, [WorkerJobService::THUMB_MAX_SIDE])[0];
        } catch (BusinessException) {
            $thumbPath = null;
        }

        try {
            $old = DB::transaction(fn () => $apply([
                'image_path' => $path,
                'thumb_path' => $thumbPath,
                'mime' => $file->getMimeType() ?: 'application/octet-stream',
                'size_bytes' => $file->getSize(),
            ]));
        } catch (\Throwable $e) {
            $this->media->delete($path);
            $this->media->delete($thumbPath);
            throw $e;
        }

        foreach ($old as $oldPath) {
            if ($oldPath && $oldPath !== $path && $oldPath !== $thumbPath) {
                $this->media->delete($oldPath);
            }
        }
    }

    /**
     * Ghi đè ảnh gốc của output (gọi trong $apply của replace): lô, lịch sử tạo ảnh và mọi bài đăng dùng output này
     * đều đổi theo. Ảnh đã tắt thì bật lại để dùng được ngay. Trả đường dẫn cũ.
     *
     * @return array<int, ?string>
     */
    public function overwriteOutput(int $outputId, array $file): array
    {
        $output = GenerationOutput::query()->lockForUpdate()->findOrFail($outputId);
        if ($output->status !== OutputStatus::Completed) {
            throw new BusinessException('IMAGE_NOT_READY', 'Chỉ cắt được ảnh đã tạo xong.', 409);
        }

        $old = [$output->image_path, $output->thumb_path];
        $output->update([...$file, 'disabled_at' => null]);

        return $old;
    }
}
