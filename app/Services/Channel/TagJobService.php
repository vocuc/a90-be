<?php

namespace App\Services\Channel;

use App\Exceptions\BusinessException;
use App\Models\Product;
use App\Models\TagJob;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerJob;
use App\Support\ProductTags;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Gắn tag sản phẩm bằng AI (a90-fe note/xay-kenh-tiktok/worker-gan-tag.txt, người dùng chốt 2026-11-21): chủ shop chọn
 * sản phẩm ở trang Sản phẩm, bấm "Gắn tag bằng AI" -> 1 việc tag trong worker_jobs; worker gắn tag của shop nhận việc qua
 * /api/v1/worker-jobs, ghép lưới ảnh anh_san_pham, gọi skill Claude /gan-tag-san-pham, trả tag -> A51 lưu như skill
 * (ProductTagService::saveAi: trường chủ shop đã sửa giữ nguyên).
 */
class TagJobService
{
    public function __construct(private ProductTagService $productTags) {}

    public static function hasWorker(int $userId): bool
    {
        return Worker::query()->assignedTo($userId, Worker::TYPE_TAG)->exists();
    }

    public function latest(User $shop): ?TagJob
    {
        return TagJob::query()->where('user_id', $shop->id)->latest('id')->first();
    }

    /** @param int[] $productIds sản phẩm của shop có ảnh anh_san_pham (sai -> 422 errors.product_ids) */
    public function create(User $shop, array $productIds): TagJob
    {
        if (! self::hasWorker($shop->id)) {
            throw new BusinessException('TAG_WORKER_REQUIRED', 'Tài khoản chưa được gắn worker gắn tag sản phẩm, liên hệ admin A51 để gắn.', 409);
        }
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        $valid = $this->productTags->taggable($shop, $ids)->pluck('id')->all();
        $bad = array_values(array_diff($ids, $valid));
        if ($bad) {
            throw ValidationException::withMessages(['product_ids' => 'Sản phẩm #'.implode(', #', $bad).' không phải của bạn hoặc chưa có ảnh trường '.ProductTagService::TAG_FIELD_KEY.'.']);
        }

        return DB::transaction(function () use ($shop, $ids) {
            User::query()->lockForUpdate()->find($shop->id);
            if (TagJob::query()->where('user_id', $shop->id)->whereIn('status', [WorkerJob::QUEUED, WorkerJob::PROCESSING])->exists()) {
                throw new BusinessException('TAG_JOB_RUNNING', 'Đang có lượt gắn tag chưa xong, chờ xong rồi bấm lại.', 409);
            }

            return TagJob::query()->create([
                'user_id' => $shop->id,
                'status' => WorkerJob::QUEUED,
                'payload' => ['product_ids' => $ids],
            ]);
        });
    }

    /** Worker nhận việc cũ nhất của các shop được gắn (việc quá hạn cũng giao lại). SKIP LOCKED: không nhận trùng. */
    public function claim(Worker $worker): ?TagJob
    {
        $worker->assertType(Worker::TYPE_TAG);

        return DB::transaction(function () use ($worker) {
            $job = TagJob::query()
                ->whereIn('user_id', DB::table('worker_user')->select('user_id')->where('worker_id', $worker->id))
                ->where(fn ($q) => $q->where('status', WorkerJob::QUEUED)
                    ->orWhere(fn ($q) => $q->where('status', WorkerJob::PROCESSING)->where('lease_expires_at', '<', now())))
                ->orderBy('id')
                ->lock('for update skip locked')
                ->first();
            if (! $job) {
                return null;
            }
            $job->update([
                'status' => WorkerJob::PROCESSING,
                'worker_id' => $worker->id,
                'attempts' => $job->attempts + 1,
                'claimed_at' => now(),
                'lease_expires_at' => now()->addMinutes(TagJob::LEASE_MINUTES),
            ]);

            return $job;
        });
    }

    /**
     * Dữ liệu gửi worker: products [{id, name, prompt_note, cover: {image_id, thumb_url} (ảnh anh_san_pham), ai_label, tags,
     * tag_sources}] (sản phẩm đã xoá / bỏ ảnh sau khi bấm thì bỏ qua), tag_options, label_max.
     */
    public function request(TagJob $job): array
    {
        $shop = User::query()->findOrFail($job->user_id);

        return [
            'products' => $this->productTags->untagged($shop, true, $job->payload['product_ids'] ?? []),
            'tag_options' => ProductTags::options(),
            'label_max' => ProductTags::LABEL_MAX,
        ];
    }

    /** Worker trả tag: items [{id, image_id, ai_label, tags}] -> saveAi. */
    public function complete(Worker $worker, int $jobId, array $items): array
    {
        return DB::transaction(function () use ($worker, $jobId, $items) {
            $job = $this->lockAssigned($worker, $jobId);
            $allowed = $job->payload['product_ids'] ?? [];
            $items = array_values(array_filter($items, fn ($i) => is_array($i) && in_array((int) ($i['id'] ?? 0), $allowed, true)));
            $result = $items ? $this->productTags->saveAi(User::query()->findOrFail($job->user_id), $items)
                : ['updated' => [], 'skipped' => (object) [], 'errors' => []];
            $job->update([
                'status' => WorkerJob::COMPLETED,
                'result' => ['updated' => count($result['updated']), 'skipped' => count((array) $result['skipped']),
                    'errors' => count($result['errors']), 'requested' => count($allowed)],
                'lease_expires_at' => null,
                'finished_at' => now(),
                'error_code' => null,
                'error_message' => null,
            ]);

            return $result;
        });
    }

    public function fail(Worker $worker, int $jobId, string $errorCode, ?string $errorMessage): void
    {
        DB::transaction(function () use ($worker, $jobId, $errorCode, $errorMessage) {
            $this->lockAssigned($worker, $jobId)->update([
                'status' => WorkerJob::FAILED,
                'lease_expires_at' => null,
                'finished_at' => now(),
                'error_code' => $errorCode,
                'error_message' => Str::limit((string) ($errorMessage ?: 'Worker không gắn được tag.'), 490, ''),
            ]);
        });
    }

    private function lockAssigned(Worker $worker, int $jobId): TagJob
    {
        $job = TagJob::query()->lockForUpdate()->find($jobId);
        if (! $job || $job->status !== WorkerJob::PROCESSING || $job->worker_id !== $worker->id) {
            throw new BusinessException('JOB_NOT_ASSIGNED', 'Job không còn được giao cho worker này.', 409);
        }

        return $job;
    }
}
