<?php

namespace App\Http\Controllers\Api\V1\Creator;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreativeRequest;
use App\Http\Resources\CreatorCreativeResource;
use App\Http\Resources\GenerationResource;
use App\Models\AiCreative;
use App\Services\CreativeService;
use App\Services\GenerationInput;
use App\Services\GenerationService;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class CreativeController extends Controller
{
    private const DETAIL_RELATIONS = ['images', 'config', 'model', 'hashtags', 'assets'];

    public function __construct(private CreativeService $creatives) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['draft', 'published', 'paused'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $creatives = AiCreative::query()
            ->where('creator_id', $request->user()->id)
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->with(['images', 'hashtags'])
            ->latest('updated_at')
            ->paginate($filters['per_page'] ?? 20);

        return CreatorCreativeResource::collection($creatives);
    }

    public function store(CreativeRequest $request): JsonResponse
    {
        $creative = $this->creatives->create($request->user(), $request->validated());

        return (new CreatorCreativeResource($creative->load(self::DETAIL_RELATIONS)))->response()->setStatusCode(201);
    }

    public function show(Request $request, AiCreative $creative): CreatorCreativeResource
    {
        $this->authorizeOwner($request, $creative);

        return new CreatorCreativeResource($creative->load(self::DETAIL_RELATIONS));
    }

    public function update(CreativeRequest $request, AiCreative $creative): CreatorCreativeResource
    {
        $this->authorizeOwner($request, $creative);
        $creative = $this->creatives->update($creative, $request->validated());

        return new CreatorCreativeResource($creative->load(self::DETAIL_RELATIONS));
    }

    public function destroy(Request $request, AiCreative $creative): JsonResponse
    {
        $this->authorizeOwner($request, $creative);
        $this->creatives->delete($creative);

        return response()->json(['message' => 'Đã xóa AI Creative.']);
    }

    public function publish(Request $request, AiCreative $creative): CreatorCreativeResource
    {
        $this->authorizeOwner($request, $creative);

        return new CreatorCreativeResource($this->creatives->publish($creative)->load(self::DETAIL_RELATIONS));
    }

    public function pause(Request $request, AiCreative $creative): CreatorCreativeResource
    {
        $this->authorizeOwner($request, $creative);

        return new CreatorCreativeResource($this->creatives->pause($creative)->load(self::DETAIL_RELATIONS));
    }

    /** Tạo thử (kể cả bản nháp), không trừ Credit. Ảnh do worker tạo như lượt tạo thường. */
    public function test(Request $request, AiCreative $creative, GenerationService $generations, SettingService $settings): JsonResponse
    {
        $this->authorizeOwner($request, $creative);

        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:'.$settings->int('generation.max_outputs_limit')],
            'idempotency_key' => ['required', 'string', 'max:100'],
        ]);
        $input = GenerationInput::fromRequest($request, $creative, $settings->int('generation.input_max_size_kb'));

        $generation = $generations->createTest(
            $request->user(), $creative, $input, $data['quantity'], $data['idempotency_key'],
        );

        return (new GenerationResource($generation->load('creative')))->response()->setStatusCode(202);
    }

    private function authorizeOwner(Request $request, AiCreative $creative): void
    {
        abort_unless($creative->creator_id === $request->user()->id, 404);
    }
}
