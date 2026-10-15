<?php

namespace Tests\Feature;

use App\Models\AiCreative;
use App\Models\Generation;
use App\Models\ImageBatch;
use App\Models\TikTokPost;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ImageBatchTest extends TestCase
{
    // ---- Helpers ----

    /** @param  array<int, array{0: AiCreative, 1: int}>  $items  [mẫu, số ảnh] */
    private function createBatch(User $user, array $items, array $extra = []): TestResponse
    {
        return $this->actingAs($user)->post('/api/v1/image-batches', array_merge([
            'items' => array_map(fn (array $item) => [
                'creative_id' => $item[0]->id,
                'quantity' => $item[1],
                'inputs' => ['product_image' => UploadedFile::fake()->image('product.png', 64, 64), 'aspectRatio' => '1:1'],
            ], $items),
            'idempotency_key' => (string) Str::uuid(),
        ], $extra), ['Accept' => 'application/json']);
    }

    /** Lô 1 mẫu, ảnh đã tạo xong. */
    private function completedBatch(User $user, int $quantity = 1): ImageBatch
    {
        $id = $this->createBatch($user, [[$this->publishedCreative($this->creator()), $quantity]])->assertCreated()->json('data.id');
        $this->runWorker(array_fill(0, $quantity, null));

        return ImageBatch::query()->findOrFail($id);
    }

    // ---- Tạo lô ----

    public function test_create_charges_total_and_splits_quantity_over_max_outputs(): void
    {
        $a = $this->publishedCreative($this->creator(), price: 1000); // max_outputs = 5
        $b = $this->publishedCreative($this->creator(), price: 2000);
        $user = $this->buyer(credit: 20000);

        $this->createBatch($user, [[$a, 7], [$b, 1]])
            ->assertCreated()
            ->assertJsonPath('data.status', 'generating')
            ->assertJsonPath('data.image_count', 8)
            ->assertJsonPath('data.generating_count', 8)
            ->assertJsonPath('data.images.0.status', 'queued')
            ->assertJsonPath('data.images.7.creative.price_per_image', 2000)
            ->assertJsonPath('data.post_count', 0);

        $this->assertSame(11000, $this->balance($user));
        // 7 ảnh mẫu A = 5 + 2, dùng chung ảnh đầu vào
        $generations = Generation::query()->where('creative_id', $a->id)->orderBy('id')->get();
        $this->assertSame([5, 2], $generations->pluck('quantity')->all());
        $this->assertSame($generations[0]->input_paths, $generations[1]->input_paths);

        $this->runWorker([...array_fill(0, 7, null), 'CONTENT_BLOCKED']);
        $this->actingAs($user)->getJson('/api/v1/image-batches')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'completed')
            ->assertJsonPath('data.0.image_count', 8)
            ->assertJsonPath('data.0.failed_count', 1)
            ->assertJsonMissingPath('data.0.images');
        $this->assertNotNull($this->actingAs($user)->getJson('/api/v1/image-batches')->json('data.0.cover_url'));
        $this->assertSame(13000, $this->balance($user));
        $this->assertLedgerMatchesBalance($user);
    }

    public function test_same_creative_only_once_per_batch(): void
    {
        $creative = $this->publishedCreative($this->creator());
        $user = $this->buyer(credit: 5000);

        $this->createBatch($user, [[$creative, 1], [$creative, 2]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.1.creative_id');
        $this->assertSame(0, ImageBatch::query()->count());
    }

    public function test_insufficient_credit_for_total_creates_nothing(): void
    {
        $user = $this->buyer(credit: 1500);

        $this->createBatch($user, [[$this->publishedCreative($this->creator(), price: 1000), 2]])
            ->assertStatus(402)
            ->assertJsonPath('code', 'INSUFFICIENT_CREDIT')
            ->assertJsonPath('context.required', 2000);

        $this->assertSame(0, ImageBatch::query()->count());
        $this->assertSame(0, Generation::query()->count());
        $this->assertSame(1500, $this->balance($user));
    }

    public function test_inputs_are_validated_per_item(): void
    {
        $user = $this->buyer(credit: 5000);
        $a = $this->publishedCreative($this->creator());
        $b = $this->publishedCreative($this->creator());

        $this->actingAs($user)->post('/api/v1/image-batches', [
            'items' => [
                ['creative_id' => $a->id, 'quantity' => 1, 'inputs' => ['product_image' => UploadedFile::fake()->image('a.png'), 'aspectRatio' => '1:1']],
                ['creative_id' => $b->id, 'quantity' => 1, 'inputs' => ['aspectRatio' => '1:1']],
            ],
            'idempotency_key' => 'k1',
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.1.inputs.product_image')
            ->assertJsonMissingValidationErrors('items.0.inputs.product_image');

        $this->actingAs($user)->postJson('/api/v1/image-batches', [
            'items' => [['creative_id' => 999999, 'quantity' => 1]], 'idempotency_key' => 'k2',
        ])->assertStatus(404)->assertJsonPath('code', 'CREATIVE_NOT_AVAILABLE');

        $this->assertSame(5000, $this->balance($user));
    }

    public function test_same_idempotency_key_returns_same_batch(): void
    {
        $creative = $this->publishedCreative($this->creator());
        $user = $this->buyer(credit: 5000);

        $first = $this->createBatch($user, [[$creative, 1]], ['idempotency_key' => 'same'])->json('data.id');
        $second = $this->createBatch($user, [[$creative, 1]], ['idempotency_key' => 'same'])->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(4000, $this->balance($user));
    }

    // ---- Tạo lại / sửa nguyên liệu ----

    public function test_regenerate_charges_and_replaces_image_in_same_position(): void
    {
        $user = $this->buyer(credit: 5000);
        $batch = $this->completedBatch($user, 2);
        $image = $batch->images()->get()[1];

        $new = $this->actingAs($user)->postJson("/api/v1/image-batches/{$batch->id}/images/{$image->id}/regenerate", [
            'idempotency_key' => 'regen-1',
        ])->assertStatus(202)
            ->assertJsonPath('data.id', $image->id)
            ->assertJsonPath('data.position', 2)
            ->assertJsonPath('data.status', 'queued')
            ->json('data');

        $this->assertNotSame($image->output_id, $new['output_id']);
        $this->assertSame(2000, $this->balance($user));
        $this->assertSame($image->generation_id, Generation::query()->find($new['generation_id'])->parent_generation_id);

        // Đang tạo lại: chưa tạo lại / xoá lô được
        $this->actingAs($user)->postJson("/api/v1/image-batches/{$batch->id}/images/{$image->id}/regenerate", ['idempotency_key' => 'regen-2'])
            ->assertStatus(409)->assertJsonPath('code', 'IMAGE_NOT_READY');
        $this->actingAs($user)->deleteJson("/api/v1/image-batches/{$batch->id}")
            ->assertStatus(409)->assertJsonPath('code', 'BATCH_NOT_DELETABLE');

        $this->runWorker([null]);
        $this->actingAs($user)->getJson("/api/v1/image-batches/{$batch->id}")
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.images.1.status', 'completed')
            ->assertJsonPath('data.images.1.output_id', $new['output_id']);
    }

    public function test_edit_inputs_keeps_old_file_and_changes_aspect_ratio(): void
    {
        $user = $this->buyer(credit: 5000);
        $batch = $this->completedBatch($user);
        $image = $batch->images()->first();
        $oldGeneration = Generation::query()->find($image->generation_id);

        $inputs = $this->actingAs($user)->getJson("/api/v1/image-batches/{$batch->id}/images/{$image->id}/inputs")
            ->assertOk()
            ->assertJsonPath('data.creative.title', 'Ảnh sản phẩm studio')
            ->assertJsonPath('data.inputs.aspectRatio', '1:1')
            ->json('data.inputs');
        $fileId = $inputs['product_image'][0]['file_id'];
        $this->assertStringStartsWith('f_', $fileId);

        $new = $this->actingAs($user)->post("/api/v1/image-batches/{$batch->id}/images/{$image->id}/regenerate", [
            'inputs' => ['aspectRatio' => '4:5'],
            'keep_files' => ['product_image' => [$fileId]],
            'idempotency_key' => 'edit-1',
        ], ['Accept' => 'application/json'])->assertStatus(202)->json('data');

        $generation = Generation::query()->find($new['generation_id']);
        $this->assertSame('4:5', $generation->aspect_ratio);
        $this->assertSame($oldGeneration->input_paths['product_image'], $generation->input_paths['product_image']);

        // file_id sai -> lỗi theo trường
        $this->actingAs($user)->post("/api/v1/image-batches/{$batch->id}/images/{$image->id}/regenerate", [
            'inputs' => ['aspectRatio' => '1:1'],
            'keep_files' => ['product_image' => ['f_wrong']],
            'idempotency_key' => 'edit-2',
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('keep_files.product_image');
    }

    public function test_image_inputs_null_when_input_file_was_deleted(): void
    {
        $user = $this->buyer(credit: 5000);
        $batch = $this->completedBatch($user);
        $image = $batch->images()->first();
        Storage::disk('media')->delete(Generation::query()->find($image->generation_id)->input_paths['product_image']);

        $this->actingAs($user)->getJson("/api/v1/image-batches/{$batch->id}/images/{$image->id}/inputs")
            ->assertOk()
            ->assertJsonPath('data.inputs', null);
    }

    // ---- Cắt ảnh ----

    public function test_crop_overwrites_original_image_free_of_charge(): void
    {
        $user = $this->buyer(credit: 5000);
        $batch = $this->completedBatch($user);
        $image = $batch->images()->with('output')->first();
        $oldPath = $image->output->image_path;
        $balance = $this->balance($user);

        $data = $this->actingAs($user)->post("/api/v1/image-batches/{$batch->id}/images/{$image->id}/crop", [
            'image' => UploadedFile::fake()->image('crop.jpg', 300, 400),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.id', $image->id)
            ->assertJsonPath('data.output_id', $image->output_id)
            ->assertJsonPath('data.status', 'completed')
            ->json('data');

        $output = $image->output->fresh();
        $this->assertNotSame($oldPath, $output->image_path);
        $this->assertStringContainsString($output->image_path, $data['image_url']);
        $this->assertNotNull($output->thumb_path);
        Storage::disk('media')->assertExists($output->image_path);
        Storage::disk('media')->assertMissing($oldPath);
        $this->assertSame($balance, $this->balance($user));
    }

    public function test_crop_rejects_busy_image_and_other_users(): void
    {
        $user = $this->buyer(credit: 5000);
        $batch = $this->completedBatch($user);
        $image = $batch->images()->first();
        $url = "/api/v1/image-batches/{$batch->id}/images/{$image->id}/crop";
        $file = fn () => ['image' => UploadedFile::fake()->image('crop.jpg', 30, 40)];

        $this->actingAs($this->buyer())->post($url, $file(), ['Accept' => 'application/json'])->assertNotFound();
        $this->actingAs($user)->post($url, [], ['Accept' => 'application/json'])->assertJsonValidationErrors('image');

        $this->actingAs($user)->postJson("/api/v1/image-batches/{$batch->id}/images/{$image->id}/regenerate", ['idempotency_key' => 'r1'])
            ->assertStatus(202);
        $this->actingAs($user)->post($url, $file(), ['Accept' => 'application/json'])
            ->assertStatus(409)->assertJsonPath('code', 'IMAGE_NOT_READY');
    }

    // ---- Xoá ----

    public function test_delete_image_keeps_at_least_one(): void
    {
        $user = $this->buyer(credit: 5000);
        $batch = $this->completedBatch($user, 2);
        [$first, $second] = $batch->images()->get()->all();

        $this->actingAs($user)->deleteJson("/api/v1/image-batches/{$batch->id}/images/{$first->id}")->assertNoContent();
        $this->actingAs($user)->deleteJson("/api/v1/image-batches/{$batch->id}/images/{$second->id}")
            ->assertStatus(409)->assertJsonPath('code', 'BATCH_LAST_IMAGE');
        // Ảnh vẫn còn trong lịch sử tạo ảnh
        $this->assertSame(2, Generation::query()->first()->outputs()->count());
    }

    public function test_delete_batch_keeps_posts_and_history(): void
    {
        $user = $this->buyer(credit: 5000);
        $batch = $this->completedBatch($user);
        $image = $batch->images()->first();
        $postId = $this->postFromBatch($user, $batch, [$image])->assertCreated()->json('data.id');

        $this->actingAs($user)->deleteJson("/api/v1/image-batches/{$batch->id}")->assertNoContent();

        $this->assertSame(0, ImageBatch::query()->count());
        $this->assertNull(TikTokPost::query()->find($postId)->image_batch_id);
        $this->assertSame(1, Generation::query()->count());
    }

    // ---- Đăng TikTok từ lô ----

    private function postFromBatch(User $user, ImageBatch $batch, array $images): TestResponse
    {
        return $this->actingAs($user)->postJson('/api/v1/tiktok/posts/from-images', [
            'images' => array_map(fn ($image) => ['generation_id' => $image->generation_id, 'output_id' => $image->output_id], $images),
            'image_batch_id' => $batch->id,
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }

    public function test_batch_can_be_posted_many_times_and_posts_are_independent(): void
    {
        $user = $this->buyer(credit: 5000);
        $batch = $this->completedBatch($user, 2);
        $images = $batch->images()->get();

        $this->postFromBatch($user, $batch, [$images[1]])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending_review')
            ->assertJsonPath('data.source', 'batch')
            ->assertJsonPath('data.image_batch_id', $batch->id)
            ->assertJsonPath('data.image_count', 1)
            ->assertJsonPath('data.images.0.output_id', $images[1]->output_id);
        $this->postFromBatch($user, $batch, $images->all())->assertCreated()->assertJsonPath('data.image_count', 2);

        $this->actingAs($user)->getJson("/api/v1/image-batches/{$batch->id}")->assertJsonPath('data.post_count', 2);

        // Tạo lại ảnh trong lô: bài đã tạo giữ ảnh cũ
        $this->actingAs($user)->postJson("/api/v1/image-batches/{$batch->id}/images/{$images[1]->id}/regenerate", ['idempotency_key' => 'r1'])
            ->assertStatus(202);
        $this->assertSame($images[1]->output_id, TikTokPost::query()->orderBy('id')->first()->images()->first()->output_id);
    }

    public function test_cannot_use_batch_of_other_user(): void
    {
        $owner = $this->buyer(credit: 5000);
        $batch = $this->completedBatch($owner);
        $other = $this->buyer(credit: 5000);
        $image = $batch->images()->first();

        $this->postFromBatch($other, $batch, [$image])->assertStatus(422)->assertJsonValidationErrors('image_batch_id');
        $this->actingAs($other)->getJson("/api/v1/image-batches/{$batch->id}")->assertNotFound();
        $this->actingAs($other)->deleteJson("/api/v1/image-batches/{$batch->id}")->assertNotFound();
        $this->actingAs($other)->getJson('/api/v1/image-batches')->assertJsonCount(0, 'data');
    }
}
