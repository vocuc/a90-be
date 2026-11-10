<?php

namespace App\Http\Controllers\Api\V1\Creator;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreativeRequest;
use App\Http\Resources\ProductResource;
use App\Models\Hashtag;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\MediaStorage;
use App\Services\SettingService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sản phẩm cho tạo lô tự động (note/auto-tao-lo.txt ở repo frontend). Sản phẩm của người khác -> 404.
 * Chủ sản phẩm chia sẻ được cho tài khoản khác theo email: người được chia sẻ thấy và dùng sản phẩm để tạo ảnh,
 * nhưng sửa / xoá / bật tắt / chia sẻ chỉ chủ sản phẩm làm được (người khác -> 404).
 * Mỗi ảnh liên kết tới key một trường ảnh trong creative. Hashtag chỉ lưu, tạo lô tự động không xét. File ảnh cũ không xoá vì lượt tạo ảnh cũ dùng thẳng đường dẫn.
 */
class ProductController extends Controller
{
    /** Số email tối đa mỗi lần chia sẻ. */
    public const MAX_SHARES = 50;

    private const RELATIONS = ['images', 'hashtags', 'user.creatorProfile'];

    public function __construct(
        private MediaStorage $media,
        private SettingService $settings,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:50']]);

        $products = Product::query()
            ->accessibleBy($request->user())
            ->withAutoBatchCount($request->user())->withGeneratedImageCount($request->user())
            ->with(self::RELATIONS)->withCount('sharedWith')
            ->latest('id')
            ->paginate($data['per_page'] ?? 20);

        return ProductResource::collection($products);
    }

    public function show(Request $request, int $product): ProductResource
    {
        return $this->resource($request, Product::query()->accessibleBy($request->user())->findOrFail($product)->id);
    }

    /** Danh sách người được chia sẻ (chỉ chủ sản phẩm). { data: [{ id, email, name, shared_at }] } */
    public function shares(Request $request, int $product): JsonResponse
    {
        $product = $this->findProduct($request, $product);

        return response()->json(['data' => $this->presentShares($product)]);
    }

    /**
     * { emails: string[] } Ghi đè toàn bộ danh sách chia sẻ (gửi [] = bỏ chia sẻ hết). Email phải là tài khoản A51,
     * không phải chính mình. Lỗi -> 422 errors["emails.i"].
     */
    public function updateShares(Request $request, int $product): JsonResponse
    {
        $product = $this->findProduct($request, $product);
        $data = $request->validate(['emails' => ['present', 'array']]);

        $product->sharedWith()->sync($this->recipientIds($request, $data['emails']));

        return response()->json(['data' => $this->presentShares($product)]);
    }

    /**
     * Chia sẻ / bỏ chia sẻ hàng loạt (nút "Chia sẻ" cạnh "Thêm sản phẩm"):
     * { action: share|unshare, emails: string[] (>= 1), all?: bool, product_ids?: int[] }.
     * all = true: mọi sản phẩm hiện có của mình (sản phẩm thêm sau không tự chia sẻ). Chỉ thêm / bớt các email gửi lên,
     * người đang được chia sẻ khác giữ nguyên. Sản phẩm không phải của mình -> 422 errors.product_ids.
     * -> { data: { action, product_count, emails } }
     */
    public function bulkShare(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:share,unshare'],
            'emails' => ['required', 'array', 'min:1'],
            'all' => ['nullable', 'boolean'],
            'product_ids' => ['required_unless:all,true', 'array'],
            'product_ids.*' => ['integer', 'distinct'],
        ], [
            'emails.required' => 'Vui lòng nhập ít nhất 1 email.',
            'emails.min' => 'Vui lòng nhập ít nhất 1 email.',
            'product_ids.required_unless' => 'Vui lòng chọn sản phẩm.',
        ]);

        $query = Product::query()->ownedBy($request->user());
        if (! ($data['all'] ?? false)) {
            $ids = array_map('intval', $data['product_ids']);
            $query->whereKey($ids);
            if (! $ids || $query->clone()->count() !== count($ids)) {
                throw ValidationException::withMessages(['product_ids' => $ids ? 'Có sản phẩm không tồn tại.' : 'Vui lòng chọn sản phẩm.']);
            }
        }
        $userIds = $this->recipientIds($request, $data['emails']);

        $products = $query->get(['id']);
        DB::transaction(function () use ($products, $userIds, $data) {
            foreach ($products as $product) {
                $data['action'] === 'share'
                    ? $product->sharedWith()->syncWithoutDetaching($userIds)
                    : $product->sharedWith()->detach($userIds);
            }
        });

        return response()->json(['data' => [
            'action' => $data['action'],
            'product_count' => $products->count(),
            'emails' => array_map(fn ($e) => mb_strtolower(trim($e)), $data['emails']),
        ]]);
    }

    /**
     * Email -> id tài khoản A51 nhận chia sẻ. Lỗi theo từng dòng -> 422 errors["emails.i"].
     *
     * @return int[]
     */
    private function recipientIds(Request $request, array $emails): array
    {
        $request->validate([
            'emails' => ['max:'.self::MAX_SHARES],
            'emails.*' => ['required', 'string', 'email', 'max:255'],
        ], [
            'emails.max' => 'Tối đa '.self::MAX_SHARES.' email mỗi lần.',
            'emails.*.required' => 'Vui lòng nhập email.',
            'emails.*.email' => 'Email không hợp lệ.',
        ]);

        $emails = array_map(fn ($e) => mb_strtolower(trim($e)), $emails);
        $users = User::query()->whereIn(DB::raw('LOWER(email)'), $emails)->get(['id', 'email'])
            ->keyBy(fn (User $u) => mb_strtolower($u->email));
        $errors = [];
        $ids = [];
        foreach ($emails as $i => $email) {
            $user = $users[$email] ?? null;
            if (! $user) {
                $errors["emails.{$i}"] = "Không tìm thấy tài khoản A51 có email {$email}.";
            } elseif ($user->id === $request->user()->id) {
                $errors["emails.{$i}"] = 'Không thể chia sẻ cho chính mình.';
            } else {
                $ids[] = $user->id;
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return array_values(array_unique($ids));
    }

    /** multipart: name?, prompt_note?, hashtags[]?, images[i][file], images[i][field_key] */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(false), $this->messages());

        $product = DB::transaction(function () use ($request, $data) {
            $product = Product::query()->create(['user_id' => $request->user()->id, ...$this->attributes($data)]);
            $this->syncImages($request, $product, $data['images']);
            $product->hashtags()->sync(Hashtag::syncPivot($data['hashtags'] ?? []));

            return $product;
        });

        return $this->resource($request, $product->id)->response()->setStatusCode(201);
    }

    /**
     * multipart: name?, prompt_note? (không gửi = bỏ), hashtags[]? (không gửi = bỏ hết), images[i][id] (giữ ảnh cũ) hoặc images[i][file] (ảnh mới),
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
            $product->update($this->attributes($data));
            $this->syncImages($request, $product, $data['images']);
            $product->hashtags()->sync(Hashtag::syncPivot($data['hashtags'] ?? []));
        });

        return $this->resource($request, $product->id);
    }

    /**
     * File ảnh sản phẩm (cùng domain với API): nút "Tạo ảnh" của thẻ sản phẩm tải ảnh về trình duyệt rồi gửi lại như
     * ảnh người dùng chọn khi tạo lô tay. Không tải thẳng signed URL S3 được vì bucket không cho CORS.
     */
    public function imageFile(Request $request, int $product, int $image): Response
    {
        $path = Product::query()->accessibleBy($request->user())->findOrFail($product)->images()->findOrFail($image)->path;

        return response($this->media->get($path), 200, [
            'Content-Type' => $this->media->mimeType($path),
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    /** { is_active: bool } Công tắc Hoạt động / Tắt: sản phẩm tắt không được tạo lô tự động. */
    public function setActive(Request $request, int $product): ProductResource
    {
        $product = $this->findProduct($request, $product);
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $product->update(['is_active' => $data['is_active']]);

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
        $old = $product->images()->get(['id', 'path', 'thumb_path'])->keyBy('id');
        $product->images()->delete();

        $directory = "products/{$product->user_id}";
        foreach (array_values($images) as $position => $image) {
            if (! empty($image['id'])) {
                $kept = $old[(int) $image['id']];
                [$path, $thumbPath] = [$kept->path, $kept->thumb_path];
            } else {
                $file = $request->file("images.{$position}.file");
                $path = $this->media->storeUpload($file, $directory);
                $thumbPath = $this->storeThumb($file, $directory);
            }
            $product->images()->create([
                'field_key' => $image['field_key'],
                'path' => $path,
                'thumb_path' => $thumbPath,
                'position' => $position,
            ]);
        }
    }

    /** Ảnh gốc giữ nguyên để tạo ảnh; thumbnail chỉ để hiển thị. Lỗi tạo thumbnail không chặn lưu sản phẩm. */
    private function storeThumb(UploadedFile $file, string $directory): ?string
    {
        try {
            return $this->media->storeOptimizedImage($file, $directory, [ProductImage::THUMB_WIDTH], true)[0];
        } catch (BusinessException) {
            return null;
        }
    }

    private function rules(bool $updating): array
    {
        $maxKb = $this->settings->int('generation.input_max_size_kb');

        return [
            'name' => ['nullable', 'string', 'max:255'],
            'prompt_note' => ['nullable', 'string', 'max:'.Product::PROMPT_NOTE_MAX],
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
            'prompt_note.max' => 'Yêu cầu tối đa '.number_format(Product::PROMPT_NOTE_MAX, 0, ',', '.').' ký tự.',
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

    /** Cột lưu thẳng từ form; yêu cầu chỉ có khoảng trắng -> null. */
    private function attributes(array $data): array
    {
        return [
            'name' => $data['name'] ?? null,
            'prompt_note' => trim((string) ($data['prompt_note'] ?? '')) ?: null,
        ];
    }

    private function resource(Request $request, int $id): ProductResource
    {
        return new ProductResource(Product::query()
            ->withAutoBatchCount($request->user())->withGeneratedImageCount($request->user())
            ->with(self::RELATIONS)->withCount('sharedWith')
            ->findOrFail($id));
    }

    /** @return array<int, array{id: int, email: string, name: ?string, shared_at: mixed}> */
    private function presentShares(Product $product): array
    {
        return $product->sharedWith()->orderBy('product_shares.id')->get(['users.id', 'users.email', 'users.name'])
            ->map(fn (User $u) => ['id' => $u->id, 'email' => $u->email, 'name' => $u->name, 'shared_at' => $u->pivot->created_at])
            ->all();
    }

    /** Chỉ chủ sản phẩm: sửa, xoá, bật tắt, chia sẻ. */
    private function findProduct(Request $request, int $id): Product
    {
        return Product::query()->ownedBy($request->user())->findOrFail($id);
    }
}
