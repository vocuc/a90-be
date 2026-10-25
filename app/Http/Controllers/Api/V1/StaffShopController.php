<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CreativeStatus;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Resources\CreativeCardResource;
use App\Models\AiCreative;
use App\Models\ShopStaff;
use App\Services\MediaStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Phía nhân viên của shop (note/chia-se-tai-nguyen.txt ở repo frontend): rời shop, logo chèn vào ảnh (nhân viên không có
 * shop nên không có ảnh đại diện shop), creative Đang bán của shop (cả riêng tư) để chọn khi tạo lô tay.
 * Không phải nhân viên -> 403 NOT_STAFF.
 */
class StaffShopController extends Controller
{
    /** Cạnh dài tối đa (px) khi lưu logo, như ảnh đại diện shop. */
    private const LOGO_MAX_SIDE = 512;

    public function __construct(private MediaStorage $media) {}

    public function leave(Request $request): Response
    {
        $this->membership($request)->delete();

        return response()->noContent();
    }

    /** multipart image -> { data: { logo_url } }. Lưu một logo cho tài khoản, tải logo mới thì thay logo cũ. */
    public function uploadLogo(Request $request): JsonResponse
    {
        $this->membership($request);
        $request->validate(['image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']], [
            'image.required' => 'Vui lòng chọn ảnh logo.',
            'image.mimes' => 'Chỉ nhận ảnh JPG, PNG hoặc WebP.',
            'image.max' => 'Logo tối đa 5MB.',
        ]);

        $user = $request->user();
        $old = $user->logo_path;
        [$path] = $this->media->storeOptimizedImage($request->file('image'), 'logos/'.$user->id, [self::LOGO_MAX_SIDE]);
        $user->forceFill(['logo_path' => $path])->save();
        $this->media->delete($old);

        return response()->json(['data' => ['logo_url' => $this->media->url($path)]]);
    }

    /** Creative Đang bán của shop (công khai + riêng tư), mới đăng trước; q: tìm theo tên. */
    public function creatives(Request $request): AnonymousResourceCollection
    {
        $membership = $this->membership($request);
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $creatives = AiCreative::query()
            ->where('creator_id', $membership->owner_id)
            ->where('status', CreativeStatus::Published)
            ->whereNull('hidden_at')
            ->with(['images', 'category', 'creator.creatorProfile', 'hashtags'])
            ->when($data['q'] ?? null, fn ($q, $term) => $q->where('title', 'like', '%'.addcslashes($term, '%_\\').'%'))
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate($data['per_page'] ?? 20);

        return CreativeCardResource::collection($creatives);
    }

    private function membership(Request $request): ShopStaff
    {
        return $request->user()->staffMembership
            ?? throw new BusinessException('NOT_STAFF', 'Bạn không phải nhân viên của shop nào.', 403);
    }
}
