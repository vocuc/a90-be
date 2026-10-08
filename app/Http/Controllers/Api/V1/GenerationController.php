<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OutputStatus;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Resources\GenerationResource;
use App\Models\AiCreative;
use App\Models\Generation;
use App\Models\GenerationOutput;
use App\Services\GenerationService;
use App\Services\MediaStorage;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GenerationController extends Controller
{
    public function __construct(
        private GenerationService $generations,
        private SettingService $settings,
    ) {}

    public function store(Request $request): JsonResponse
    {
        // Chỉ nhận đúng các trường này. Giá, model, prompt... luôn đọc từ database.
        $data = $request->validate([
            'creative_id' => ['required', 'integer'],
            'input_image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.$this->settings->int('generation.input_max_size_kb')],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.$this->settings->int('generation.max_outputs_limit')],
            'aspect_ratio' => ['required', 'string', 'max:10'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ]);

        $creative = AiCreative::query()->find($data['creative_id'])
            ?? throw BusinessException::creativeNotAvailable();

        $generation = $this->generations->create(
            $request->user(), $creative, $data['input_image'], $data['quantity'], $data['aspect_ratio'], $data['idempotency_key'],
        );

        return (new GenerationResource($generation->load('creative')))->response()->setStatusCode(202);
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $generations = Generation::query()
            ->where('user_id', $request->user()->id)
            ->with(['creative', 'outputs'])
            ->latest('id')
            ->paginate(min((int) $request->query('per_page', 20), 50));

        return GenerationResource::collection($generations);
    }

    public function show(Request $request, Generation $generation): GenerationResource
    {
        $this->authorizeOwner($request, $generation);

        return new GenerationResource($generation->load(['creative', 'outputs']));
    }

    /** Bản rút gọn để frontend polling. */
    public function status(Request $request, Generation $generation): JsonResponse
    {
        $this->authorizeOwner($request, $generation);
        $media = app(MediaStorage::class);

        return response()->json(['data' => [
            'id' => $generation->id,
            'status' => $generation->status,
            'success_count' => $generation->success_count,
            'failed_count' => $generation->failed_count,
            'outputs' => $generation->outputs()->get()->map(fn (GenerationOutput $o) => [
                'id' => $o->id,
                'position' => $o->position,
                'status' => $o->status,
                'image_url' => $o->status === OutputStatus::Completed ? $media->url($o->image_path) : null,
                'error_code' => $o->error_code,
            ]),
        ]]);
    }

    public function retry(Request $request, Generation $generation): JsonResponse
    {
        $this->authorizeOwner($request, $generation);

        $data = $request->validate([
            'output_ids' => ['required', 'array', 'min:1', 'max:'.$this->settings->int('generation.max_outputs_limit')],
            'output_ids.*' => ['integer', 'distinct'],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ]);

        $retry = $this->generations->retry($request->user(), $generation, $data['output_ids'], $data['idempotency_key']);

        return (new GenerationResource($retry->load('creative')))->response()->setStatusCode(202);
    }

    public function download(Request $request, Generation $generation, GenerationOutput $output): JsonResponse
    {
        $this->authorizeOwner($request, $generation);
        abort_unless($output->generation_id === $generation->id && $output->status === OutputStatus::Completed, 404);

        $extension = pathinfo($output->image_path, PATHINFO_EXTENSION);
        $filename = "ai90-{$generation->id}-{$output->position}.{$extension}";

        return response()->json(['data' => [
            'url' => app(MediaStorage::class)->url($output->image_path, $filename),
            'filename' => $filename,
            'expires_in_minutes' => config('ai90.signed_url_ttl_minutes'),
        ]]);
    }

    private function authorizeOwner(Request $request, Generation $generation): void
    {
        abort_unless($generation->user_id === $request->user()->id, 404);
    }
}
