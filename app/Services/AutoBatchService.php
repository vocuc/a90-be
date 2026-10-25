<?php

namespace App\Services;

use App\Enums\CreativeStatus;
use App\Exceptions\BusinessException;
use App\Models\AiCreative;
use App\Models\CreativeGroup;
use App\Models\CreditWallet;
use App\Models\ImageBatch;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Tạo lô tự động (note/auto-tao-lo.txt ở repo frontend): người dùng bấm "Tạo tự động" với số lô, mỗi lô = 1 sản phẩm
 * x các creative của 1 nhóm (mỗi creative 1 ảnh), ảnh sản phẩm điền vào trường ảnh có key = field_key.
 *
 * Hashtag: sản phẩm không có hashtag thì bỏ qua; nhóm chỉ ghép với sản phẩm khi có creative dùng được trùng ít nhất
 * một hashtag với sản phẩm, khi đó tạo cả nhóm (mọi creative dùng được, kể cả creative không trùng hashtag).
 *
 * Thứ tự: sản phẩm có ít lượt tự động nhất trước (bằng nhau thì sản phẩm tạo trước), rồi nhóm mà sản phẩm đó
 * chạy ít nhất (bằng nhau thì chọn ngẫu nhiên, xem AutoBatchPlanner::shuffle). Cặp không có creative nào dùng được thì bỏ qua, không tính lượt.
 * Hết tiền giữa chừng thì dừng; lô là đơn vị nhỏ nhất (trừ tiền cả lô như lô tạo tay).
 *
 * Nhân viên của shop (note/chia-se-tai-nguyen.txt ở repo frontend) dùng sản phẩm / nhóm / creative của shop chủ, trừ ví
 * nhân viên; lượt đếm riêng từng người nên mỗi kênh tự xoay vòng đủ mọi cặp.
 */
class AutoBatchService
{
    /** Số lô tối đa mỗi lần bấm: chặn kỹ thuật (một request tạo tuần tự từng lô), người dùng bấm nhiều lần được. */
    public const MAX_COUNT = 100;

    public const STOP_INSUFFICIENT_CREDIT = 'insufficient_credit';

    public const STOP_NO_VALID_PAIR = 'no_valid_pair';

    public function __construct(private ImageBatchService $batches) {}

    /**
     * Xem trước $count lô sẽ tạo (cùng quy tắc, không tạo, không xét số dư).
     *
     * @return array<int, array{product: Product, group: CreativeGroup, items: array, cost: int}>
     */
    public function preview(User $user, int $count): array
    {
        $planner = $this->planner($user);
        $plans = [];
        while (count($plans) < $count && ($plan = $planner->next())) {
            $planner->consume($plan);
            $plans[] = $plan;
        }

        return $plans;
    }

    /**
     * Tạo tối đa $count lô. Mỗi lô có idempotency_key riêng "auto:{key}:{n}": gửi lại cùng key thì trả đúng các lô
     * đã tạo, không trừ tiền lần nữa.
     *
     * @return array{batches: ImageBatch[], stopped: ?string, required: ?int}
     */
    public function create(User $user, int $count, bool $insertLogo, string $idempotencyKey): array
    {
        // Hai request cùng lúc của một người dùng không chọn trùng cặp
        $lock = Cache::lock("auto-batch:{$user->id}", 120);
        $lock->block(30);

        try {
            $planner = $this->planner($user);
            $created = [];
            $stopped = null;
            $required = null;

            for ($n = 0; $n < $count; $n++) {
                $key = "auto:{$idempotencyKey}:{$n}";
                if ($existing = ImageBatch::query()->where('user_id', $user->id)->where('idempotency_key', $key)->first()) {
                    $created[] = $existing;

                    continue;
                }

                $plan = $planner->next();
                if (! $plan) {
                    $stopped = self::STOP_NO_VALID_PAIR;
                    break;
                }

                try {
                    $created[] = DB::transaction(function () use ($user, $plan, $key, $insertLogo) {
                        $batch = $this->batches->create($user, $plan['items'], $key, $insertLogo, [
                            'source' => ImageBatch::SOURCE_AUTO,
                            'product_id' => $plan['product']->id,
                            'creative_group_id' => $plan['group']->id,
                            'shop_owner_id' => $user->shopOwnerId(),
                        ]);
                        DB::table('auto_batch_product_counts')->upsert(
                            ['user_id' => $user->id, 'product_id' => $plan['product']->id, 'count' => 1],
                            ['user_id', 'product_id'],
                            ['count' => DB::raw('count + 1')],
                        );
                        DB::table('auto_batch_counts')->upsert(
                            ['user_id' => $user->id, 'product_id' => $plan['product']->id, 'creative_group_id' => $plan['group']->id, 'count' => 1],
                            ['user_id', 'product_id', 'creative_group_id'],
                            ['count' => DB::raw('count + 1')],
                        );

                        return $batch;
                    });
                } catch (BusinessException $e) {
                    if ($e->errorCode !== 'INSUFFICIENT_CREDIT' || ! $created) {
                        throw $e;
                    }
                    $stopped = self::STOP_INSUFFICIENT_CREDIT;
                    $required = $e->context['required'] ?? $plan['cost'];
                    break;
                }

                $planner->consume($plan);
            }

            if (! $created) {
                throw new BusinessException('AUTO_NOTHING_TO_CREATE', 'Chưa có sản phẩm và nhóm creative Đang bán nào dùng được để tạo lô.', 422);
            }

            return ['batches' => $created, 'stopped' => $stopped, 'required' => $required];
        } finally {
            $lock->release();
        }
    }

    public function balance(User $user): int
    {
        return (int) (CreditWallet::query()->where('user_id', $user->id)->value('balance') ?? 0);
    }

    private function planner(User $user): AutoBatchPlanner
    {
        $products = Product::query()->usableBy($user)->withAutoBatchCount($user)->has('hashtags')->with(['images', 'hashtags'])->orderBy('id')->get();
        $groups = CreativeGroup::query()
            ->where('user_id', $user->resourceOwnerId())
            ->with(['creatives.config', 'creatives.model', 'creatives.hashtags'])
            ->orderBy('id')
            ->get();
        $counts = DB::table('auto_batch_counts')
            ->where('user_id', $user->id)
            ->whereIn('product_id', $products->modelKeys())
            ->get()
            ->mapWithKeys(fn ($row) => ["{$row->product_id}:{$row->creative_group_id}" => (int) $row->count])
            ->all();

        return new AutoBatchPlanner($products, $groups, $counts, fn (Product $p, CreativeGroup $g) => $this->items($user, $p, $g->creatives));
    }

    /**
     * Creative dùng được của nhóm với sản phẩm, kèm nguyên liệu. Bỏ qua (không báo): không Đang bán / bị ẩn / không phải
     * của chủ nhóm (người dùng, hoặc shop chủ khi là nhân viên) / model tạm tắt; không có trường ảnh nào trùng key; còn trường bắt buộc không có giá trị.
     * Không creative dùng được nào trùng hashtag với sản phẩm thì nhóm không ghép với sản phẩm này (trả rỗng).
     *
     * @param  Collection<int, AiCreative>  $creatives
     * @return array<int, array{creative: AiCreative, input: GenerationInput, quantity: int, kept: array<string, string>}>
     */
    private function items(User $user, Product $product, Collection $creatives): array
    {
        $paths = $product->images->pluck('path', 'field_key')->all();
        $items = [];

        foreach ($creatives as $creative) {
            if ($creative->creator_id !== $user->resourceOwnerId() || $creative->status !== CreativeStatus::Published
                || $creative->hidden_at !== null || ! $creative->model?->is_active || ! $creative->config) {
                continue;
            }
            if ($item = $this->fill($creative, $paths)) {
                $items[] = $item;
            }
        }

        $hashtagIds = $product->hashtags->modelKeys();
        foreach ($items as $item) {
            if (array_intersect($hashtagIds, $item['creative']->hashtags->modelKeys())) {
                return $items;
            }
        }

        return [];
    }

    /** @param  array<string, string>  $paths  ảnh sản phẩm theo field_key */
    private function fill(AiCreative $creative, array $paths): ?array
    {
        $config = $creative->config;
        $kept = [];
        $values = [];

        foreach (CreativeTemplate::buyerFields($config->input_schema ?? []) as $field) {
            $key = $field['key'];
            if ($field['type'] === 'aspect_ratio') {
                continue;
            }
            if ($field['type'] === 'image') {
                if (isset($paths[$key])) {
                    $kept[$key] = $paths[$key];
                } elseif (! empty($field['required'])) {
                    return null;
                }

                continue;
            }
            // Trường khác: giá trị mặc định của Creator, không có thì để trống
            $default = $field['default_value'] ?? null;
            if ($default === null || $default === '' || $default === []) {
                if (! empty($field['required']) && $field['type'] !== 'toggle') {
                    return null;
                }

                continue;
            }
            $values[$key] = $field['type'] === 'toggle' ? filter_var($default, FILTER_VALIDATE_BOOLEAN) : $default;
        }

        if (! $kept) {
            return null;
        }
        $values[CreativeTemplate::ASPECT_KEY] = $config->default_aspect_ratio;

        return [
            'creative' => $creative,
            'input' => new GenerationInput([], $values, $config->default_aspect_ratio),
            'quantity' => 1,
            'kept' => $kept,
        ];
    }
}
