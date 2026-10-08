<?php

namespace App\Http\Controllers\Api\V1\Creator;

use App\Enums\CreativeStatus;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\AiCreative;
use App\Models\AiCreativeImage;
use App\Services\MediaStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Ảnh mẫu của Creative (chỉ để hiển thị trên sàn). */
class CreativeImageController extends Controller
{
    private const MAX_IMAGES = 10;

    public function __construct(private MediaStorage $media) {}

    public function store(Request $request, AiCreative $creative): JsonResponse
    {
        $this->authorizeOwner($request, $creative);
        $request->validate(['image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240']]);

        if ($creative->images()->count() >= self::MAX_IMAGES) {
            throw new BusinessException('TOO_MANY_IMAGES', 'Tối đa '.self::MAX_IMAGES.' ảnh mẫu.', 422);
        }

        $image = $creative->images()->create([
            'path' => $this->media->storeUpload($request->file('image'), "creatives/{$creative->id}"),
            'sort_order' => (int) $creative->images()->max('sort_order') + 1,
        ]);

        return response()->json(['data' => $this->present($image)], 201);
    }

    public function destroy(Request $request, AiCreative $creative, AiCreativeImage $image): JsonResponse
    {
        $this->authorizeOwner($request, $creative);
        abort_unless($image->creative_id === $creative->id, 404);

        if ($creative->status === CreativeStatus::Published && $creative->images()->count() <= 1) {
            throw BusinessException::invalidState('Creative đang xuất bản phải có ít nhất 1 ảnh mẫu.');
        }

        $image->delete();
        $this->media->delete($image->path);

        return response()->json(['message' => 'Đã xóa ảnh mẫu.']);
    }

    public function reorder(Request $request, AiCreative $creative): JsonResponse
    {
        $this->authorizeOwner($request, $creative);
        $data = $request->validate([
            'image_ids' => ['required', 'array', 'min:1'],
            'image_ids.*' => ['integer', 'distinct'],
        ]);

        $images = $creative->images()->get()->keyBy('id');
        abort_unless(collect($data['image_ids'])->every(fn ($id) => $images->has($id)), 422, 'Có ảnh không thuộc Creative này.');

        DB::transaction(function () use ($data, $images) {
            foreach ($data['image_ids'] as $order => $id) {
                $images[$id]->update(['sort_order' => $order]);
            }
        });

        return response()->json([
            'data' => $creative->images()->get()->map(fn ($image) => $this->present($image)),
        ]);
    }

    private function present(AiCreativeImage $image): array
    {
        return [
            'id' => $image->id,
            'url' => $this->media->url($image->path),
            'sort_order' => $image->sort_order,
        ];
    }

    private function authorizeOwner(Request $request, AiCreative $creative): void
    {
        abort_unless($creative->creator_id === $request->user()->id, 404);
    }
}
