<?php

namespace App\Http\Controllers\Api\V1\Creator;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Resources\CreatorProfileResource;
use App\Models\CreatorProfile;
use App\Models\CreatorWallet;
use App\Services\MediaStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    /** Cạnh dài tối đa (px) khi lưu ảnh đại diện và ảnh bìa. */
    private const AVATAR_MAX_SIDE = 512;

    private const COVER_MAX_SIDE = 1920;

    public function __construct(private MediaStorage $media) {}

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->creatorProfile()->exists()) {
            throw BusinessException::invalidState('Bạn đã có Creator Profile.');
        }
        // Nhân viên của shop không được mở shop riêng (note/chia-se-tai-nguyen.txt ở repo frontend)
        if ($user->staffMembership()->exists()) {
            throw BusinessException::invalidState('Bạn đang là nhân viên của một shop. Hãy rời shop trước khi mở shop riêng.');
        }

        $data = $request->validate($this->rules());

        $profile = DB::transaction(function () use ($user, $data) {
            $profile = $user->creatorProfile()->create($data);
            CreatorWallet::query()->firstOrCreate(['user_id' => $user->id]);

            return $profile;
        });

        return (new CreatorProfileResource($profile))->response()->setStatusCode(201);
    }

    public function show(Request $request): CreatorProfileResource
    {
        return new CreatorProfileResource($this->profile($request));
    }

    public function update(Request $request): CreatorProfileResource
    {
        $profile = $this->profile($request);
        $profile->update($request->validate($this->rules($profile, partial: true)));

        return new CreatorProfileResource($profile);
    }

    public function uploadAvatar(Request $request): CreatorProfileResource
    {
        return $this->uploadImage($request, 'avatar_path', self::AVATAR_MAX_SIDE);
    }

    public function uploadCover(Request $request): CreatorProfileResource
    {
        return $this->uploadImage($request, 'cover_path', self::COVER_MAX_SIDE);
    }

    private function uploadImage(Request $request, string $column, int $maxSide): CreatorProfileResource
    {
        $request->validate(['image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);

        $profile = $this->profile($request);
        $old = $profile->{$column};
        [$path] = $this->media->storeOptimizedImage($request->file('image'), 'creators/'.$profile->user_id, [$maxSide]);
        $profile->update([$column => $path]);
        $this->media->delete($old);

        return new CreatorProfileResource($profile);
    }

    private function profile(Request $request): CreatorProfile
    {
        return $request->user()->creatorProfile()->firstOrFail();
    }

    private function rules(?CreatorProfile $profile = null, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'shop_name' => [$required, 'string', 'max:100'],
            'username' => [$required, 'string', 'min:3', 'max:50', 'regex:/^[a-z0-9_.-]+$/', Rule::unique('creator_profiles', 'username')->ignore($profile?->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'website' => ['nullable', 'url', 'max:255'],
            'social_links' => ['nullable', 'array:facebook,instagram,tiktok,youtube,x,threads'],
            'social_links.*' => ['nullable', 'url', 'max:255'],
        ];
    }
}
