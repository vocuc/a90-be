<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\GenerationOutput;
use App\Models\Product;
use App\Models\Worker;
use App\Services\MediaStorage;
use App\Services\ShopImageLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * API cho worker tạo video: worker chọn template, xin đúng số ảnh template cần (count), nhận ảnh ngẫu nhiên của MỘT sản
 * phẩm trong các lô ảnh của shop được gắn (ShopImageLibrary). Sản phẩm chọn ngẫu nhiên trong các sản phẩm có đủ count ảnh;
 * lô không gắn sản phẩm (tạo tay) không dùng. Ảnh dùng lại thoải mái, A51 không lưu gì (video dựng xong chỉ nằm trên máy
 * worker). Bài đăng video có lịch dùng API /video-posts (note/lap-lich-worker.txt ở repo frontend).
 * Worker loại đăng bài (note/quan-ly-worker.txt): gắn nhiều shop thì gửi user_id (shop lấy ảnh), gắn 1 shop thì không
 * cần. Worker tạo ảnh -> 409 WORKER_TYPE_MISMATCH; chưa gắn tài khoản -> 409 VIDEO_ACCOUNT_NOT_ASSIGNED; gắn nhiều mà
 * không gửi user_id -> 422 user_id; không sản phẩm nào đủ ảnh -> 422 NOT_ENOUGH_IMAGES
 * (available = số ảnh của sản phẩm nhiều ảnh nhất).
 */
class WorkerVideoImageController extends Controller
{
    public const MAX_COUNT = 50;

    public function index(Request $request, MediaStorage $media, ShopImageLibrary $library): JsonResponse
    {
        $data = $request->validate([
            'count' => ['required', 'integer', 'min:1', 'max:'.self::MAX_COUNT],
            'user_id' => ['nullable', 'integer'],
        ]);
        $count = (int) $data['count'];

        /** @var Worker $worker */
        $worker = $request->attributes->get('worker');
        $worker->assertType(Worker::TYPE_PUBLISH);
        $users = $worker->users()->get();
        if ($users->isEmpty()) {
            throw new BusinessException('VIDEO_ACCOUNT_NOT_ASSIGNED', 'Worker chưa được gắn tài khoản A51.', 409);
        }
        if (isset($data['user_id'])) {
            $user = $users->firstWhere('id', (int) $data['user_id'])
                ?? throw ValidationException::withMessages(['user_id' => 'Worker không được gắn tài khoản này.']);
        } elseif ($users->count() === 1) {
            $user = $users->first();
        } else {
            throw ValidationException::withMessages(['user_id' => 'Worker gắn nhiều tài khoản: gửi user_id của shop cần lấy ảnh.']);
        }

        $perProduct = $library->countsPerProduct($user->id);
        $candidates = $perProduct->filter(fn ($total) => $total >= $count)->keys();
        if ($candidates->isEmpty()) {
            $available = (int) ($perProduct->max() ?? 0);
            throw new BusinessException(
                'NOT_ENOUGH_IMAGES',
                "Không sản phẩm nào của shop đủ {$count} ảnh (nhiều nhất {$available}).",
                422,
                ['available' => $available, 'required' => $count],
            );
        }

        $product = Product::query()->findOrFail($candidates->random());
        $outputs = $library->productImages($user->id, $product->id)->inRandomOrder()->limit($count)->get(['id', 'image_path']);

        return response()->json(['data' => [
            'account' => ['id' => $user->id, 'name' => $user->name],
            'product' => ['id' => $product->id, 'name' => $product->name],
            'images' => $outputs->map(fn (GenerationOutput $o) => ['id' => $o->id, 'url' => $media->url($o->image_path)])->values(),
        ]]);
    }
}
