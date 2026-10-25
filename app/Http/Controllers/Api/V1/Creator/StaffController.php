<?php

namespace App\Http\Controllers\Api\V1\Creator;

use App\Http\Controllers\Controller;
use App\Http\Resources\ImageBatchResource;
use App\Models\ImageBatch;
use App\Models\ShopStaff;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Chủ shop quản lý nhân viên (note/chia-se-tai-nguyen.txt ở repo frontend). Thêm bằng email là thành nhân viên ngay;
 * mỗi người chỉ thuộc một shop, tài khoản đã có shop không làm nhân viên được.
 * Chủ shop chỉ xem (không tạo lại / xoá / đăng) các lô nhân viên tạo bằng tài nguyên shop, kể cả sau khi gỡ nhân viên.
 */
class StaffController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $staff = ShopStaff::query()
            ->where('owner_id', $request->user()->id)
            ->with('user')
            ->latest('id')
            ->get();

        return response()->json(['data' => $staff->map(fn (ShopStaff $s) => self::staff($s))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']], [
            'email.required' => 'Vui lòng nhập email.',
            'email.email' => 'Email không hợp lệ.',
        ]);
        $owner = $request->user();

        $user = User::query()->with(['creatorProfile', 'staffMembership'])->where('email', trim($data['email']))->first();
        $error = match (true) {
            ! $user => 'Không tìm thấy tài khoản với email này.',
            $user->id === $owner->id => 'Không thể thêm chính bạn.',
            $user->creatorProfile !== null => 'Tài khoản đã có shop.',
            $user->staffMembership?->owner_id === $owner->id => 'Tài khoản đã là nhân viên của shop bạn.',
            $user->staffMembership !== null => 'Tài khoản đã thuộc shop khác.',
            default => null,
        };
        if ($error) {
            throw ValidationException::withMessages(['email' => $error]);
        }

        try {
            $staff = ShopStaff::query()->create(['owner_id' => $owner->id, 'user_id' => $user->id]);
        } catch (UniqueConstraintViolationException) {
            // Hai shop thêm cùng lúc
            throw ValidationException::withMessages(['email' => 'Tài khoản đã thuộc shop khác.']);
        }

        return response()->json(['data' => self::staff($staff->setRelation('user', $user))], 201);
    }

    /** Gỡ nhân viên: mất quyền dùng tài nguyên shop ngay, lô đã tạo vẫn ở tài khoản nhân viên. */
    public function destroy(Request $request, int $staff): Response
    {
        ShopStaff::query()->where('owner_id', $request->user()->id)->findOrFail($staff)->delete();

        return response()->noContent();
    }

    /** Lô nhân viên tạo bằng tài nguyên shop, mới nhất trước; user_id: lọc theo nhân viên. */
    public function batches(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $batches = ImageBatch::query()
            ->where('shop_owner_id', $request->user()->id)
            ->when($data['user_id'] ?? null, fn ($q, $userId) => $q->where('user_id', $userId))
            ->withSummary()
            ->with('user')
            ->latest('id')
            ->paginate($data['per_page'] ?? 20);

        return ImageBatchResource::collection($batches);
    }

    public function batch(Request $request, int $batch): ImageBatchResource
    {
        $batch = ImageBatch::query()->where('shop_owner_id', $request->user()->id)->findOrFail($batch);

        return new ImageBatchResource($batch->load([...ImageBatchResource::RELATIONS, 'user'])->loadCount('posts'));
    }

    private static function staff(ShopStaff $staff): array
    {
        return [
            'id' => $staff->id,
            'user' => ['id' => $staff->user->id, 'name' => $staff->user->name, 'email' => $staff->user->email],
            'created_at' => $staff->created_at,
        ];
    }
}
