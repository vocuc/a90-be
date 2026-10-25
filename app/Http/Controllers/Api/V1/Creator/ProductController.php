<?php

namespace App\Http\Controllers\Api\V1\Creator;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreativeRequest;
use App\Http\Resources\ProductResource;
use App\Models\Hashtag;
use App\Models\Product;
use App\Services\MediaStorage;
use App\Services\SettingService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sản phẩm cho tạo lô tự động (note/auto-tao-lo.txt ở repo frontend). Sản phẩm của người khác -> 404.
 * Nhân viên của shop xem được (index, show) sản phẩm của shop chủ, không sửa được (note/chia-se-tai-nguyen.txt).
 * Mỗi ảnh liên kết tới key một trường ảnh trong creative. Hashtag: chỉ ghép với nhóm có creative trùng hashtag. File ảnh cũ không xoá vì lượt tạo ảnh cũ dùng thẳng đường dẫn.
 */
class ProductController extends Controller
{
    public function __construct(
        private MediaStorage $media,
        private SettingService $settings,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);

        $products = Product::query()
            ->usableBy($request->user())
            ->withAutoBatchCount($request->user())
            ->with(['images', 'hashtags'])
            ->latest('id')
            ->paginate($data['per_page'] ?? 20);

        return ProductResource::collection($products);
    }

    public function show(Request $request, int $product): ProductResource
    {
        return $this->resource($request, Product::query()->usableBy($request->user())->findOrFail($product)->id);
    }

    /** multipart: name?, hashtags[]?, images[i][file], images[i][field_key] */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(false), $this->messages());

        $product = DB::transaction(function () use ($request, $data) {
            $product = Product::query()->create(['user_id' => $request->user()->id, 'name' => $data['name'] ?? null]);
            $this->syncImages($request, $product, $data['images']);
            $product->hashtags()->sync(Hashtag::syncPivot($data['hashtags'] ?? []));

            return $product;
        });

        return $this->resource($request, $product->id)->response()->setStatusCode(201);
    }

    /**
     * multipart: name?, hashtags[]? (không gửi = bỏ hết), images[i][id] (giữ ảnh cũ) hoặc images[i][file] (ảnh mới),
     * images[i][field_key]; ảnh cũ không gửi lại thì bỏ.
     */
    public function update(Request $request, int $product): ProductResource
    {
        $product = $this->findProduct($request, $product);
        $data = $request->validate($this->rules(true), $this->messages());

        $ownIds = $product->images()->pluck('id')->all();
        $errors = [];
        foreach ($data['images'] as $i => $image) {
            if (! empty($image['id']) && ! in_array((int) $image['id'], $ownIds, true)) {
                $errors["images.{$i}.id"] = 'Ảnh không thuộc sản phẩm này.';
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($request, $product, $data) {
            $product->update(['name' => $data['name'] ?? null]);
            $this->syncImages($request, $product, $data['images']);
            $product->hashtags()->sync(Hashtag::syncPivot($data['hashtags'] ?? []));
        });

        return $this->resource($request, $product->id);
    }

    public function destroy(Request $request, int $product): Response
    {
        $this->findProduct($request, $product)->delete();

        return response()->noContent();
    }

    /**
     * Ghi lại toàn bộ ảnh theo thứ tự gửi lên (ảnh giữ lại dùng đường dẫn cũ, ảnh mới tải lên).
     *
     * @param  array<int, array{id?: int, field_key: string}>  $images
     */
    private function syncImages(Request $request, Product $product, array $images): void
    {
        $oldPaths = $product->images()->pluck('path', 'id');
        $product->images()->delete();

        foreach (array_values($images) as $position => $image) {
            $path = ! empty($image['id'])
                ? $oldPaths[(int) $image['id']]
                : $this->media->storeUpload($request->file("images.{$position}.file"), "products/{$product->user_id}");
            $product->images()->create(['field_key' => $image['field_key'], 'path' => $path, 'position' => $position]);
        }
    }

    private function rules(bool $updating): array
    {
        $maxKb = $this->settings->int('generation.input_max_size_kb');

        return [
            'name' => ['nullable', 'string', 'max:255'],
            // Như hashtag của Creative (CreativeRequest)
            'hashtags' => ['nullable', 'array', 'max:'.CreativeRequest::MAX_HASHTAGS],
            'hashtags.*' => ['required', 'string', 'max:50', 'regex:'.Hashtag::PATTERN, function (string $attribute, mixed $value, Closure $fail) {
                if (is_string($value) && Hashtag::slugFor($value) === '') {
                    $fail('Hashtag phải chứa chữ cái hoặc chữ số.');
                }
            }],
            'images' => ['required', 'array', 'min:1', 'list'],
            'images.*.id' => $updating ? ['nullable', 'integer'] : ['prohibited'],
            'images.*.file' => [$updating ? 'required_without:images.*.id' : 'required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.$maxKb],
            'images.*.field_key' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_]+$/', 'distinct'],
        ];
    }

    private function messages(): array
    {
        return [
            'hashtags.max' => 'Tối đa '.CreativeRequest::MAX_HASHTAGS.' hashtag.',
            'hashtags.*.regex' => 'Hashtag chỉ gồm chữ, số và dấu gạch dưới.',
            'images.required' => 'Vui lòng thêm ít nhất 1 ảnh.',
            'images.*.file.required' => 'Vui lòng chọn ảnh.',
            'images.*.file.required_without' => 'Vui lòng chọn ảnh.',
            'images.*.file.max' => 'Ảnh quá dung lượng cho phép.',
            'images.*.file.mimes' => 'Chỉ nhận ảnh JPG, PNG hoặc WebP.',
            'images.*.field_key.required' => 'Vui lòng nhập key trường nguyên liệu.',
            'images.*.field_key.regex' => 'Key chỉ gồm chữ không dấu, số và dấu gạch dưới.',
            'images.*.field_key.distinct' => 'Mỗi key chỉ dùng cho một ảnh.',
        ];
    }

    private function resource(Request $request, int $id): ProductResource
    {
        return new ProductResource(Product::query()->withAutoBatchCount($request->user())->with(['images', 'hashtags'])->findOrFail($id));
    }

    private function findProduct(Request $request, int $id): Product
    {
        return Product::query()->ownedBy($request->user())->findOrFail($id);
    }
}
