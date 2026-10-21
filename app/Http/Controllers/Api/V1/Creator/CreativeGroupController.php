<?php

namespace App\Http\Controllers\Api\V1\Creator;

use App\Http\Controllers\Controller;
use App\Http\Resources\CreativeGroupResource;
use App\Models\AiCreative;
use App\Models\CreativeGroup;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Nhóm creative cho tạo lô tự động (note/auto-tao-lo.txt ở repo frontend). Nhóm của người khác -> 404.
 * Chỉ chứa creative của chính người dùng (mọi trạng thái, lúc chạy chỉ dùng Đang bán); một creative chỉ ở một nhóm.
 */
class CreativeGroupController extends Controller
{
    private const RELATIONS = ['creatives.images'];

    /** Cũ nhất trước: đúng thứ tự tạo lô tự động. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $groups = CreativeGroup::query()
            ->where('user_id', $request->user()->id)
            ->with(self::RELATIONS)
            ->orderBy('id')
            ->get();

        return CreativeGroupResource::collection($groups);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $group = $this->save($request, new CreativeGroup(['user_id' => $request->user()->id]), $data);

        return (new CreativeGroupResource($group))->response()->setStatusCode(201);
    }

    public function update(Request $request, int $group): CreativeGroupResource
    {
        $group = $this->findGroup($request, $group);

        return new CreativeGroupResource($this->save($request, $group, $this->validated($request, $group)));
    }

    public function destroy(Request $request, int $group): Response
    {
        $this->findGroup($request, $group)->delete();

        return response()->noContent();
    }

    private function save(Request $request, CreativeGroup $group, array $data): CreativeGroup
    {
        try {
            DB::transaction(function () use ($group, $data) {
                $group->fill(['name' => $data['name']])->save();
                $group->creatives()->sync(collect($data['creative_ids'])
                    ->mapWithKeys(fn ($id, $position) => [(int) $id => ['position' => $position]])
                    ->all());
            });
        } catch (UniqueConstraintViolationException) {
            // Hai request cùng lúc thêm một creative vào hai nhóm: kiểm tra lại để báo đúng creative
            $this->validated($request, $group->exists ? $group : null);
            throw ValidationException::withMessages(['creative_ids' => 'Creative đã ở nhóm khác.']);
        }

        return $group->load(self::RELATIONS);
    }

    private function validated(Request $request, ?CreativeGroup $group = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'creative_ids' => ['required', 'array', 'min:1', 'list'],
            'creative_ids.*' => ['integer', 'distinct'],
        ], [
            'name.required' => 'Vui lòng nhập tên nhóm.',
            'creative_ids.required' => 'Vui lòng chọn ít nhất 1 creative.',
            'creative_ids.*.distinct' => 'Creative bị chọn trùng.',
        ]);

        $ids = array_map('intval', $data['creative_ids']);
        $own = AiCreative::query()->where('creator_id', $request->user()->id)->whereIn('id', $ids)->pluck('id')->all();
        $taken = CreativeGroup::query()
            ->join('creative_group_items', 'creative_group_items.creative_group_id', '=', 'creative_groups.id')
            ->whereIn('creative_group_items.creative_id', $ids)
            ->when($group, fn ($q) => $q->where('creative_groups.id', '!=', $group->id))
            ->pluck('creative_groups.name', 'creative_group_items.creative_id');

        $errors = [];
        foreach ($ids as $i => $id) {
            if (! in_array($id, $own, true)) {
                $errors["creative_ids.{$i}"] = 'Chỉ chọn được creative của bạn.';
            } elseif (isset($taken[$id])) {
                $errors["creative_ids.{$i}"] = "Creative đã ở nhóm \"{$taken[$id]}\".";
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $data;
    }

    private function findGroup(Request $request, int $id): CreativeGroup
    {
        return CreativeGroup::query()->where('user_id', $request->user()->id)->findOrFail($id);
    }
}
