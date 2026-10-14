<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\BannerResource;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\CreativeCardResource;
use App\Http\Resources\CreativeDetailResource;
use App\Http\Resources\CreatorProfileResource;
use App\Http\Resources\HashtagResource;
use App\Models\AiCreative;
use App\Models\Banner;
use App\Models\Category;
use App\Models\CreatorProfile;
use App\Models\Hashtag;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** API công khai, không cần đăng nhập. */
class MarketplaceController extends Controller
{
    private const CARD_RELATIONS = ['images', 'category', 'creator.creatorProfile', 'hashtags'];

    public function banners(): AnonymousResourceCollection
    {
        return BannerResource::collection(
            Banner::query()->where('is_active', true)->orderBy('sort_order')->orderByDesc('id')->get()
        );
    }

    public function categories(): AnonymousResourceCollection
    {
        return CategoryResource::collection(
            Category::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()
        );
    }

    /** Gợi ý hashtag khi Creator nhập / người mua tìm: hashtag có nhiều Creative đang bán nhất. */
    public function hashtags(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:60'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $listed = fn (Builder $q) => $q->listed();
        $prefix = Hashtag::slugFor($filters['q'] ?? '');

        return HashtagResource::collection(
            Hashtag::query()
                ->whereHas('creatives', $listed)
                ->withCount(['creatives' => $listed])
                ->when($prefix !== '', fn (Builder $q) => $q->where('slug', 'like', addcslashes($prefix, '%_\\').'%'))
                ->orderByDesc('creatives_count')
                ->orderBy('slug')
                ->limit($filters['limit'] ?? 20)
                ->get()
        );
    }

    public function creatives(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'category' => ['nullable', 'string'],
            // username của Creator: danh sách trên trang /creators/{username}
            'creator' => ['nullable', 'string', 'max:50'],
            // slug hoặc tên hashtag, có hoặc không có #
            'hashtag' => ['nullable', 'string', 'max:60'],
            'q' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'in:newest,popular,price_asc,price_desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = AiCreative::query()->listed()->with(self::CARD_RELATIONS)
            ->when($filters['category'] ?? null, fn (Builder $q, $slug) => $q->whereHas('category', fn ($c) => $c->where('slug', $slug)))
            ->when($filters['creator'] ?? null, fn (Builder $q, $username) => $q->whereHas('creator.creatorProfile', fn ($p) => $p->where('username', $username)))
            ->when($filters['hashtag'] ?? null, fn (Builder $q, $tag) => $q->whereHas('hashtags', fn ($h) => $h->where('slug', Hashtag::slugFor($tag))))
            ->when($filters['q'] ?? null, fn (Builder $q, $term) => $q->where('title', 'like', '%'.addcslashes($term, '%_\\').'%'));

        match ($filters['sort'] ?? 'newest') {
            'popular' => $query->orderByDesc('usage_count'),
            'price_asc' => $query->orderBy('price_per_image'),
            'price_desc' => $query->orderByDesc('price_per_image'),
            default => $query->orderByDesc('published_at'),
        };

        return CreativeCardResource::collection($query->orderByDesc('id')->paginate($filters['per_page'] ?? 20));
    }

    public function creative(string $slug): CreativeDetailResource
    {
        $creative = AiCreative::query()->listed()
            ->with([...self::CARD_RELATIONS, 'config', 'model'])
            ->where('slug', $slug)
            ->firstOrFail();

        return new CreativeDetailResource($creative);
    }

    public function creator(Request $request, string $username): JsonResponse
    {
        $profile = CreatorProfile::query()->where('username', $username)->firstOrFail();

        $creatives = AiCreative::query()->listed()->with(self::CARD_RELATIONS)
            ->where('creator_id', $profile->user_id)
            ->orderByDesc('published_at')
            ->paginate(min((int) $request->query('per_page', 20), 50));

        return response()->json([
            'data' => [
                'profile' => new CreatorProfileResource($profile),
                'creatives' => CreativeCardResource::collection($creatives)->response()->getData(true),
            ],
        ]);
    }
}
