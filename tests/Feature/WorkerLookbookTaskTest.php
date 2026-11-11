<?php

namespace Tests\Feature;

use App\Enums\SocialPostStatus;
use App\Models\LookbookTask;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\SocialPost;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Skill tạo lookbook (FlowKit a51-skills/tao-anh-look-book/thiet-ke.txt mục 3): next / complete / fail. */
class WorkerLookbookTaskTest extends TestCase
{
    private const FIELD = 'anh_san_pham';

    /** Sản phẩm của $owner, có ảnh field_key = $field (null = không có ảnh nào). */
    private function product(User $owner, string $name = 'Giày', ?string $field = self::FIELD, bool $active = true): Product
    {
        $product = Product::query()->create(['user_id' => $owner->id, 'name' => $name, 'is_active' => $active]);
        if ($field) {
            $path = "products/{$product->id}/".Str::uuid().'.jpg';
            Storage::disk('media')->put($path, 'img');
            ProductImage::query()->create(['product_id' => $product->id, 'field_key' => $field, 'path' => $path, 'position' => 0]);
        }

        return $product;
    }

    /** @return array{0: Worker, 1: string} */
    private function worker(User ...$users): array
    {
        [$worker, $token] = Worker::register('lookbook-'.Str::random(6), Worker::TYPE_PUBLISH);
        foreach ($users as $user) {
            $worker->users()->attach($user->id);
        }

        return [$worker, $token];
    }

    private function next(string $token, string $query = ''): TestResponse
    {
        return $this->withToken($token)->getJson('/api/v1/lookbook-tasks/next?image_field='.self::FIELD.$query);
    }

    private function complete(string $token, int $taskId, array $extra = []): TestResponse
    {
        return $this->withToken($token)->post("/api/v1/lookbook-tasks/{$taskId}/complete", array_merge([
            'images' => [UploadedFile::fake()->image('1.png', 300, 400), UploadedFile::fake()->image('2.jpg', 300, 400)],
            'title' => 'Lookbook giày boot',
            'content' => 'Phối đồ thu đông',
            'hashtags' => ['#lookbook', 'giayboot'],
            'outfit_ids' => [5, 9],
        ], $extra), ['Accept' => 'application/json']);
    }

    private function failTask(string $token, int $taskId, string $code = 'NOT_ENOUGH_OUTFITS'): TestResponse
    {
        return $this->withToken($token)->postJson("/api/v1/lookbook-tasks/{$taskId}/fail", ['code' => $code, 'message' => 'thiếu outfit']);
    }

    public function test_next_returns_product_with_configured_image(): void
    {
        $shop = $this->buyer();
        $product = $this->product($shop, 'Boot da');
        [$worker, $token] = $this->worker($shop);

        $data = $this->next($token)->assertOk()
            ->assertJsonPath('data.account.id', $shop->id)
            ->assertJsonPath('data.product.id', $product->id)
            ->assertJsonPath('data.product.name', 'Boot da')
            ->assertJsonPath('data.product.image.field_key', self::FIELD)
            ->assertJsonPath('data.used_outfit_ids', [])
            ->json('data');

        $this->assertNotEmpty($data['product']['image']['url']);
        $task = LookbookTask::query()->findOrFail($data['task_id']);
        $this->assertSame(LookbookTask::STATUS_PROCESSING, $task->status);
        $this->assertSame($worker->id, $task->worker_id);
    }

    public function test_next_skips_inactive_products_without_field_and_other_shops(): void
    {
        $shop = $this->buyer();
        $this->product($shop, 'Ngừng bán', active: false);
        $this->product($shop, 'Không có ảnh giày', field: 'anh_khac');
        $this->product($shop, 'Không ảnh', field: null);
        $this->product($this->buyer(), 'Shop khác');
        [, $token] = $this->worker($shop);

        $this->next($token)->assertNoContent();
    }

    public function test_never_lookbooked_first_then_oldest_lookbook_and_no_double_assign(): void
    {
        $shop = $this->buyer();
        $old = $this->product($shop, 'Có lookbook cũ');
        $recent = $this->product($shop, 'Có lookbook mới');
        $fresh = $this->product($shop, 'Chưa có');
        foreach ([[$old, 10], [$recent, 1]] as [$product, $daysAgo]) {
            LookbookTask::query()->create([
                'user_id' => $shop->id, 'product_id' => $product->id, 'status' => LookbookTask::STATUS_COMPLETED,
                'image_field' => self::FIELD, 'outfit_ids' => [3], 'finished_at' => now()->subDays($daysAgo),
            ]);
        }
        [, $token] = $this->worker($shop);

        $this->next($token)->assertJsonPath('data.product.id', $fresh->id);
        $this->next($token)->assertJsonPath('data.product.id', $old->id)->assertJsonPath('data.used_outfit_ids', [3]);
        $this->next($token)->assertJsonPath('data.product.id', $recent->id);
        $this->next($token)->assertNoContent(); // cả 3 đang processing
    }

    public function test_failed_today_is_skipped_and_stale_processing_is_reassigned(): void
    {
        $shop = $this->buyer();
        $product = $this->product($shop);
        [, $token] = $this->worker($shop);

        $taskId = $this->next($token)->json('data.task_id');
        $this->failTask($token, $taskId)->assertOk()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.error_code', 'NOT_ENOUGH_OUTFITS');
        $this->next($token)->assertNoContent(); // lỗi hôm nay -> không giao lại trong ngày

        $this->travel(1)->days();
        $retryId = $this->next($token)->assertJsonPath('data.product.id', $product->id)->json('data.task_id');

        $this->travel(LookbookTask::STALE_MINUTES + 1)->minutes();
        $againId = $this->next($token)->assertJsonPath('data.product.id', $product->id)->json('data.task_id');
        $this->assertNotSame($retryId, $againId);
        $this->assertSame('TASK_EXPIRED', LookbookTask::query()->findOrFail($retryId)->error_code);
        $this->complete($token, $retryId)->assertStatus(409)->assertJsonPath('code', 'LOOKBOOK_TASK_FINISHED');
    }

    public function test_complete_creates_pending_lookbook_post_with_uploaded_images(): void
    {
        $shop = $this->buyer();
        $product = $this->product($shop);
        [, $token] = $this->worker($shop);
        $taskId = $this->next($token)->json('data.task_id');

        $postId = $this->complete($token, $taskId)->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.post.source', 'lookbook')
            ->json('data.post.id');

        $post = SocialPost::query()->findOrFail($postId);
        $this->assertSame(SocialPostStatus::PendingReview, $post->status);
        $this->assertSame($product->id, $post->product_id);
        $this->assertSame(['lookbook', 'giayboot'], $post->hashtags);
        $images = $post->images()->get();
        $this->assertCount(2, $images);
        foreach ($images as $image) {
            $this->assertNull($image->output_id);
            Storage::disk('media')->assertExists([$image->image_path, $image->thumb_path]);
        }
        $this->assertStringEndsWith('.png', $images[0]->image_path); // file gốc giữ nguyên định dạng

        $task = LookbookTask::query()->findOrFail($taskId);
        $this->assertSame([5, 9], $task->outfit_ids);
        $this->assertSame($postId, $task->social_post_id);

        // Gửi lặp -> cùng bài
        $this->complete($token, $taskId)->assertOk()->assertJsonPath('data.post.id', $postId);
        $this->assertSame(1, SocialPost::query()->count());

        // Chủ shop thấy bài, lọc theo nguồn lookbook
        $this->actingAs($shop)->getJson('/api/v1/social/posts?source=lookbook')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.source', 'lookbook')
            ->assertJsonPath('data.0.images.0.status', 'completed')
            ->assertJsonPath('data.0.images.0.output_id', null);
        $this->actingAs($shop)->getJson('/api/v1/social/posts?source=batch')->assertOk()->assertJsonCount(0, 'data');

        // Lần sau sản phẩm có lịch sử, outfit đã dùng được trả về
        $this->travel(1)->days();
        $this->next($token)->assertJsonPath('data.used_outfit_ids', [5, 9]);
    }

    public function test_lookbook_post_can_be_published_and_worker_receives_image_urls(): void
    {
        $shop = $this->buyer();
        $this->product($shop);
        [$worker, $token] = $this->worker($shop);
        $postId = $this->complete($token, $this->next($token)->json('data.task_id'))->json('data.post.id');
        $post = SocialPost::query()->findOrFail($postId);

        // Đổi thứ tự ảnh khi duyệt rồi bấm đăng
        $imageIds = $post->images()->pluck('id')->reverse()->values();
        $this->actingAs($shop)->postJson("/api/v1/social/posts/{$postId}/publish", [
            'worker_id' => $worker->id,
            'title' => 'Lookbook',
            'images' => $imageIds->map(fn ($id) => ['image_id' => $id])->all(),
            'platforms' => ['tiktok'],
        ])->assertOk();
        $this->assertSame($imageIds->all(), $post->images()->pluck('id')->all());

        $this->withToken($token)->getJson('/api/v1/post-jobs')->assertOk()
            ->assertJsonPath('data.request.post_id', $postId)
            ->assertJsonCount(2, 'data.request.images');
    }

    public function test_complete_validates_input(): void
    {
        $shop = $this->buyer();
        $this->product($shop);
        [, $token] = $this->worker($shop);
        $taskId = $this->next($token)->json('data.task_id');

        $this->complete($token, $taskId, ['images' => [], 'title' => ''])->assertUnprocessable()->assertJsonValidationErrors(['images', 'title']);
        $this->complete($token, $taskId, ['images' => [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]])
            ->assertUnprocessable()->assertJsonValidationErrors('images.0');
        $this->assertSame(LookbookTask::STATUS_PROCESSING, LookbookTask::query()->findOrFail($taskId)->status);
    }

    public function test_task_of_other_worker_is_not_found(): void
    {
        $shop = $this->buyer();
        $this->product($shop);
        [, $token] = $this->worker($shop);
        [, $otherToken] = $this->worker($shop);
        $taskId = $this->next($token)->json('data.task_id');

        $this->complete($otherToken, $taskId)->assertNotFound();
        $this->failTask($otherToken, $taskId)->assertNotFound();
    }

    public function test_fail_is_idempotent_and_completed_task_cannot_fail(): void
    {
        $shop = $this->buyer();
        $this->product($shop, 'A');
        $this->product($shop, 'B');
        [, $token] = $this->worker($shop);

        $failedId = $this->next($token)->json('data.task_id');
        $this->failTask($token, $failedId)->assertOk();
        $this->failTask($token, $failedId, 'CHATGPT_LIMIT')->assertOk()->assertJsonPath('data.error_code', 'NOT_ENOUGH_OUTFITS');

        $doneId = $this->next($token)->json('data.task_id');
        $this->complete($token, $doneId)->assertOk();
        $this->failTask($token, $doneId)->assertStatus(409);

        $this->withToken($token)->postJson("/api/v1/lookbook-tasks/{$doneId}/fail", ['code' => 'bad code'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_worker_account_rules(): void
    {
        $a = $this->buyer();
        $b = $this->buyer();
        $productB = $this->product($b);

        $this->next($this->worker()[1])->assertStatus(409)->assertJsonPath('code', 'WORKER_ACCOUNT_NOT_ASSIGNED');

        [, $multi] = $this->worker($a, $b);
        $this->next($multi)->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->next($multi, '&user_id='.$b->id)->assertOk()->assertJsonPath('data.product.id', $productB->id);
        $this->next($multi, '&user_id='.$this->buyer()->id)->assertUnprocessable()->assertJsonValidationErrors('user_id');

        [, $imageToken] = Worker::register('img-'.Str::random(4), Worker::TYPE_IMAGE);
        $this->next($imageToken)->assertStatus(409)->assertJsonPath('code', 'WORKER_TYPE_MISMATCH');

        $this->withToken($multi)->getJson('/api/v1/lookbook-tasks/next')->assertUnprocessable()->assertJsonValidationErrors('image_field');
    }
}
