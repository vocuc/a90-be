<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OutputStatus;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\GenerationOutput;
use App\Models\Worker;
use App\Services\MediaStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API cho worker tạo video: worker chọn template, xin đúng số ảnh template cần (count), nhận ảnh ngẫu nhiên trong các lô
 * ảnh của shop được gắn (lô của chính tài khoản + lô nhân viên tạo bằng tài nguyên shop). Ảnh dùng lại thoải mái,
 * A51 không lưu gì (video dựng xong chỉ nằm trên máy worker).
 * Worker chưa gắn tài khoản -> 409 VIDEO_ACCOUNT_NOT_ASSIGNED; shop không đủ ảnh -> 422 NOT_ENOUGH_IMAGES (kèm available).
 */
class WorkerVideoImageController extends Controller
{
    public const MAX_COUNT = 50;

    public function index(Request $request, MediaStorage $media): JsonResponse
    {
        $data = $request->validate([
            'count' => ['required', 'integer', 'min:1', 'max:'.self::MAX_COUNT],
        ]);
        $count = (int) $data['count'];

        /** @var Worker $worker */
        $worker = $request->attributes->get('worker');
        $user = $worker->videoUser;
        if (! $user) {
            throw new BusinessException('VIDEO_ACCOUNT_NOT_ASSIGNED', 'Worker chưa được gắn tài khoản A51 tạo video.', 409);
        }

        $outputs = $this->shopImages($user->resourceOwnerId())->inRandomOrder()->limit($count)->get(['id', 'image_path']);
        if ($outputs->count() < $count) {
            // Lấy ít hơn count = shop chỉ có bấy nhiêu ảnh
            throw new BusinessException(
                'NOT_ENOUGH_IMAGES',
                "Shop chỉ có {$outputs->count()} ảnh, cần {$count}.",
                422,
                ['available' => $outputs->count(), 'required' => $count],
            );
        }

        return response()->json(['data' => [
            'account' => ['id' => $user->id, 'name' => $user->name],
            'images' => $outputs->map(fn (GenerationOutput $o) => ['id' => $o->id, 'url' => $media->url($o->image_path)])->values(),
        ]]);
    }

    /** Ảnh đã tạo xong trong các lô của shop (mỗi ảnh 1 lần dù nằm ở nhiều lô). */
    private function shopImages(int $ownerId): Builder
    {
        return GenerationOutput::query()
            ->where('status', OutputStatus::Completed)
            ->whereNotNull('image_path')
            ->whereIn('id', fn ($q) => $q->select('image_batch_images.output_id')
                ->from('image_batch_images')
                ->join('image_batches', 'image_batches.id', '=', 'image_batch_images.image_batch_id')
                ->where(fn ($w) => $w->where('image_batches.user_id', $ownerId)->orWhere('image_batches.shop_owner_id', $ownerId)));
    }
}
