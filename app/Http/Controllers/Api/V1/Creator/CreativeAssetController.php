<?php

namespace App\Http\Controllers\Api\V1\Creator;

use App\Http\Controllers\Controller;
use App\Models\AiCreativeAsset;
use App\Services\MediaStorage;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Nguyên liệu ẩn: Creator tải ảnh lên trước (chưa cần có Creative), rồi gửi asset_id trong trường hidden_image
 * khi lưu Creative. Ảnh giữ nguyên chất lượng vì được gửi thẳng tới model.
 */
class CreativeAssetController extends Controller
{
    public function __construct(private SettingService $settings) {}

    /** Giới hạn Template Builder cần biết trước khi Creator thêm trường. */
    public function limits(): JsonResponse
    {
        return response()->json(['data' => [
            'max_hidden_images' => $this->settings->int('generation.max_hidden_images'),
            'input_max_size_kb' => $this->settings->int('generation.input_max_size_kb'),
        ]]);
    }

    public function store(Request $request, MediaStorage $media): JsonResponse
    {
        $request->validate(['image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp',
            'max:'.$this->settings->int('generation.input_max_size_kb')]]);

        $creatorId = $request->user()->id;
        $asset = AiCreativeAsset::query()->create([
            'creator_id' => $creatorId,
            'path' => $media->storeUpload($request->file('image'), "creatives/assets/{$creatorId}"),
        ]);

        return response()->json(['data' => [
            'id' => $asset->id,
            'url' => $media->url($asset->path),
        ]], 201);
    }
}
