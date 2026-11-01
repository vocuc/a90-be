<?php

namespace App\Http\Controllers\Api\V1\Creator;

use App\Http\Controllers\Controller;
use App\Http\Resources\VideoTemplateResource;
use App\Models\VideoTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Quản lý video template của shop (note/quan-ly-video-template.txt ở repo frontend). Cần hồ sơ người bán.
 * Template của người khác -> 404.
 */
class VideoTemplateController extends Controller
{
    /** Link video TikTok: tiktok.com, www.tiktok.com, m.tiktok.com, vt.tiktok.com, vm.tiktok.com. */
    private const TIKTOK_URL = '~^https?://((www|m|vt|vm)\.)?tiktok\.com(/\S*)?$~i';

    /** Mới tạo trước. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);

        $templates = VideoTemplate::query()
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->paginate($data['per_page'] ?? 20);

        return VideoTemplateResource::collection($templates);
    }

    public function show(Request $request, int $template): VideoTemplateResource
    {
        return new VideoTemplateResource($this->find($request, $template));
    }

    public function store(Request $request): JsonResponse
    {
        $template = VideoTemplate::query()->create(['user_id' => $request->user()->id, ...$this->validated($request)]);

        return (new VideoTemplateResource($template))->response()->setStatusCode(201);
    }

    public function update(Request $request, int $template): VideoTemplateResource
    {
        $template = $this->find($request, $template);
        $template->update($this->validated($request));

        return new VideoTemplateResource($template);
    }

    public function destroy(Request $request, int $template): Response
    {
        $this->find($request, $template)->delete();

        return response()->noContent();
    }

    private function find(Request $request, int $id): VideoTemplate
    {
        return VideoTemplate::query()->where('user_id', $request->user()->id)->findOrFail($id);
    }

    private function validated(Request $request): array
    {
        $request->merge([
            'name' => is_string($request->input('name')) ? trim($request->input('name')) : $request->input('name'),
            'video_url' => is_string($request->input('video_url')) ? trim($request->input('video_url')) : $request->input('video_url'),
        ]);

        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'video_url' => ['required', 'string', 'max:500', 'regex:'.self::TIKTOK_URL],
        ], [
            'name.required' => 'Nhập tên template.',
            'name.max' => 'Tên tối đa 150 ký tự.',
            'video_url.required' => 'Nhập link video TikTok mẫu.',
            'video_url.max' => 'Link tối đa 500 ký tự.',
            'video_url.regex' => 'Link phải là link video TikTok (tiktok.com).',
        ]);
    }
}
