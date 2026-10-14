<?php

namespace Tests\Feature;

use App\Models\AiCreativeImage;
use App\Models\Banner;
use App\Models\GenerationOutput;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageOptimizationTest extends TestCase
{
    public function test_creative_sample_image_is_resized_to_webp_with_thumbnail(): void
    {
        $creator = $this->creator();
        $creative = $this->publishedCreative($creator);
        $upload = UploadedFile::fake()->image('big.jpg', 3000, 2000);

        $response = $this->actingAs($creator)->post("/api/v1/creator/creatives/{$creative->id}/images", [
            'image' => $upload,
        ], ['Accept' => 'application/json'])->assertCreated();

        $image = AiCreativeImage::query()->findOrFail($response->json('data.id'));
        $this->assertStringEndsWith('.webp', $image->path);
        $this->assertStringEndsWith('_600.webp', $image->thumb_path);
        $this->assertSame([1600, 1067], $this->dimensions($image->path));
        $this->assertSame([600, 400], $this->dimensions($image->thumb_path));
        $this->assertLessThan($upload->getSize(), Storage::disk('media')->size($image->path));
        $response->assertJsonPath('data.thumb_url', "https://media.test/{$image->thumb_path}");

        // Xóa ảnh thì xóa cả thumbnail.
        $this->deleteJson("/api/v1/creator/creatives/{$creative->id}/images/{$image->id}")->assertOk();
        Storage::disk('media')->assertMissing([$image->path, $image->thumb_path]);
    }

    public function test_marketplace_cover_uses_thumbnail_and_falls_back_to_original(): void
    {
        $creative = $this->publishedCreative($this->creator());

        // Ảnh cũ chưa có thumbnail
        $this->getJson("/api/v1/creatives/{$creative->slug}")
            ->assertOk()->assertJsonPath('data.cover_url', 'https://media.test/creatives/sample.png');

        $creative->images()->update(['thumb_path' => 'creatives/sample_600.webp']);
        $this->getJson("/api/v1/creatives/{$creative->slug}")
            ->assertOk()
            ->assertJsonPath('data.cover_url', 'https://media.test/creatives/sample_600.webp')
            ->assertJsonPath('data.images.0.url', 'https://media.test/creatives/sample.png');
    }

    public function test_worker_output_keeps_original_and_gets_thumbnail(): void
    {
        $buyer = $this->buyer(credit: 10000);
        $generationId = $this->generate($buyer, $this->publishedCreative($this->creator()))->assertStatus(202)->json('data.id');

        $token = $this->workerToken();
        $jobId = $this->claimJob($token)->assertOk()->json('data.id');
        $upload = UploadedFile::fake()->image('result.png', 2048, 1024);
        $this->withToken($token)->post("/api/v1/jobs/{$jobId}", ['status' => 'completed', 'image' => $upload],
            ['Accept' => 'application/json'])->assertOk();

        // Ảnh gốc giữ nguyên để tải về, thumbnail WebP 600px để hiển thị danh sách.
        $output = GenerationOutput::query()->findOrFail($jobId);
        $this->assertStringEndsWith('.png', $output->image_path);
        $this->assertSame([2048, 1024], $this->dimensions($output->image_path));
        $this->assertStringStartsWith('generations/outputs/', $output->thumb_path);
        $this->assertStringEndsWith('.webp', $output->thumb_path);
        $this->assertSame([600, 300], $this->dimensions($output->thumb_path));

        $this->actingAs($buyer)->getJson("/api/v1/generations/{$generationId}")
            ->assertJsonPath('data.outputs.0.image_url', "https://media.test/{$output->image_path}")
            ->assertJsonPath('data.outputs.0.thumb_url', "https://media.test/{$output->thumb_path}");
        $this->getJson("/api/v1/generations/{$generationId}/status")
            ->assertJsonPath('data.outputs.0.thumb_url', "https://media.test/{$output->thumb_path}");
    }

    public function test_small_image_is_not_upscaled(): void
    {
        $creator = $this->creator();
        $creative = $this->publishedCreative($creator);

        $id = $this->actingAs($creator)->post("/api/v1/creator/creatives/{$creative->id}/images", [
            'image' => UploadedFile::fake()->image('small.png', 300, 200),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $image = AiCreativeImage::query()->findOrFail($id);
        $this->assertSame([300, 200], $this->dimensions($image->path));
        $this->assertSame([300, 200], $this->dimensions($image->thumb_path));
    }

    public function test_avatar_and_cover_are_optimized(): void
    {
        $creator = $this->creator();
        $this->actingAs($creator);

        $this->post('/api/v1/creator/profile/avatar', [
            'image' => UploadedFile::fake()->image('avatar.png', 2000, 2000),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->post('/api/v1/creator/profile/cover', [
            'image' => UploadedFile::fake()->image('cover.jpg', 4000, 1000),
        ], ['Accept' => 'application/json'])->assertOk();

        $profile = $creator->creatorProfile()->firstOrFail();
        $this->assertStringEndsWith('.webp', $profile->avatar_path);
        $this->assertSame([512, 512], $this->dimensions($profile->avatar_path));
        $this->assertSame([1920, 480], $this->dimensions($profile->cover_path));
    }

    public function test_banner_image_is_optimized_and_broken_image_shows_form_error(): void
    {
        $admin = $this->buyer();
        $admin->forceFill(['role' => 'admin'])->save();
        $this->actingAs($admin);
        $payload = ['link_url' => '/explore', 'sort_order' => 1, 'is_active' => 1];

        $this->post('/admin/banners', [...$payload, 'image' => UploadedFile::fake()->image('banner.jpg', 3840, 1280)])
            ->assertRedirect('/admin/banners');
        $banner = Banner::query()->firstOrFail();
        $this->assertStringEndsWith('.webp', $banner->image_path);
        $this->assertSame([1920, 640], $this->dimensions($banner->image_path));

        $broken = UploadedFile::fake()->createWithContent('broken.png', substr(UploadedFile::fake()->image('x.png', 50, 50)->getContent(), 0, 40));
        $this->post('/admin/banners', [...$payload, 'image' => $broken])->assertSessionHasErrors('image');
        $this->assertSame(1, Banner::query()->count());
    }

    public function test_unreadable_image_is_rejected(): void
    {
        $creator = $this->creator();
        $creative = $this->publishedCreative($creator);
        // Đuôi và MIME khai báo là ảnh nhưng nội dung hỏng (ảnh bị cắt cụt).
        $broken = UploadedFile::fake()->createWithContent('broken.png', substr(UploadedFile::fake()->image('x.png', 50, 50)->getContent(), 0, 40));

        $this->actingAs($creator)->post("/api/v1/creator/creatives/{$creative->id}/images", [
            'image' => $broken,
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame(1, $creative->images()->count());
    }

    /** @return array{0: int, 1: int} */
    private function dimensions(string $path): array
    {
        $size = getimagesizefromstring(Storage::disk('media')->get($path));

        return [$size[0], $size[1]];
    }
}
