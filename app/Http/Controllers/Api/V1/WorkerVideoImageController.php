<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OutputStatus;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\GenerationOutput;
use App\Models\Product;
use App\Models\Worker;
use App\Services\MediaStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * API cho worker tạo video: worker chọn template, xin đúng số ảnh template cần (count), nhận ảnh ngẫu nhiên của MỘT sản
 * phẩm trong các lô ảnh của shop được gắn (lô của chính tài khoản + lô nhân viên tạo bằng tài nguyên shop). Sản phẩm chọn
 * ngẫu nhiên trong các sản phẩm có đủ count ảnh; lô không gắn sản phẩm (tạo tay) không dùng. Ảnh dùng lại thoải mái,
 * A51 không lưu gì (video dựng xong chỉ nằm trên máy worker).
 * Worker chưa gắn tài khoản -> 409 VIDEO_ACCOUNT_NOT_ASSIGNED; không sản phẩm nào đủ ảnh -> 422 NOT_ENOUGH_IMAGES
 * (available = số ảnh của sản phẩm nhiều ảnh nhất).
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

        $ownerId = $user->id;
        // Số ảnh (không trùng) của từng sản phẩm trong lô của shop
        $perProduct = DB::table('image_batch_images')
            ->join('image_batches', 'image_batches.id', '=', 'image_batch_images.image_batch_id')
            ->join('generation_outputs', 'generation_outputs.id', '=', 'image_batch_images.output_id')
            ->whereNotNull('image_batches.product_id')
            ->where('image_batches.user_id', $ownerId)
            ->where('generation_outputs.status', OutputStatus::Completed->value)
            ->whereNotNull('generation_outputs.image_path')
            ->whereNull('generation_outputs.disabled_at')
            ->groupBy('image_batches.product_id')
            ->selectRaw('image_batches.product_id, COUNT(DISTINCT image_batch_images.output_id) AS total')
            ->pluck('total', 'product_id');

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
        $outputs = $this->productImages($ownerId, $product->id)->inRandomOrder()->limit($count)->get(['id', 'image_path']);

        return response()->json(['data' => [
            'account' => ['id' => $user->id, 'name' => $user->name],
            'product' => ['id' => $product->id, 'name' => $product->name],
            'images' => $outputs->map(fn (GenerationOutput $o) => ['id' => $o->id, 'url' => $media->url($o->image_path)])->values(),
        ]]);
    }

    /** Ảnh đã tạo xong của 1 sản phẩm trong các lô của shop (mỗi ảnh 1 lần dù nằm ở nhiều lô). */
    private function productImages(int $ownerId, int $productId): Builder
    {
        return GenerationOutput::query()
            ->usable()
            ->whereIn('id', fn ($q) => $q->select('image_batch_images.output_id')
                ->from('image_batch_images')
                ->join('image_batches', 'image_batches.id', '=', 'image_batch_images.image_batch_id')
                ->where('image_batches.product_id', $productId)
                ->where('image_batches.user_id', $ownerId));
    }
}
