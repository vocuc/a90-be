<?php

namespace Tests\Feature;

use App\Models\Worker;
use Tests\TestCase;

/** Chủ shop xem worker được gắn (note/quan-ly-worker.txt ở repo frontend). */
class ShopWorkerTest extends TestCase
{
    private const URL = '/api/v1/creator/workers';

    public function test_lists_workers_assigned_to_shop_with_functions(): void
    {
        $shop = $this->creator();
        $other = $this->creator();

        // Tạo ảnh cho shop + một shop khác, đang online
        [$image, $imageToken] = Worker::register('b-anh', Worker::TYPE_IMAGE);
        $image->users()->attach([$shop->id, $other->id]);
        $image->forceFill(['last_seen_at' => now()->subMinute()])->save();

        // Đăng bài + tạo video cho shop, offline
        [$publish] = Worker::register('a-dang-bai', Worker::TYPE_PUBLISH);
        $publish->users()->attach($shop->id);
        $publish->forceFill(['last_seen_at' => now()->subHour()])->save();

        // Không hiện: worker của shop khác, worker đang tắt
        [$foreign] = Worker::register('c-khac');
        $foreign->users()->attach($other->id);
        [$off] = Worker::register('d-tat');
        $off->users()->attach($shop->id);
        $off->update(['is_active' => false]);

        $data = $this->actingAs($shop)->getJson(self::URL)->assertOk()->assertJsonCount(2, 'data')->json('data');

        $this->assertSame('a-dang-bai', $data[0]['name']);
        $this->assertSame('publish', $data[0]['type']);
        $this->assertSame(['publish', 'video'], $data[0]['functions']);
        $this->assertArrayNotHasKey('publish_account', $data[0]);
        $this->assertFalse($data[0]['online']);

        $this->assertSame('b-anh', $data[1]['name']);
        $this->assertSame('image', $data[1]['type']);
        $this->assertSame(['image'], $data[1]['functions']);
        $this->assertTrue($data[1]['online']);
        $this->assertSame($imageToken, $data[1]['token']);
    }

    public function test_requires_shop(): void
    {
        $this->actingAs($this->buyer())->getJson(self::URL)->assertForbidden();
        $this->actingAs($this->creator())->getJson(self::URL)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_shop_renames_assigned_worker(): void
    {
        $shop = $this->creator();
        [$worker] = Worker::register('may-1', Worker::TYPE_PUBLISH);
        $worker->users()->attach($shop->id);
        Worker::register('trung-ten');

        $url = self::URL."/{$worker->id}/name";

        $this->actingAs($shop)->patchJson($url, ['name' => '  May dang bai  '])
            ->assertOk()->assertJsonPath('name', 'May dang bai')->assertJsonPath('type', 'publish');
        $this->assertSame('May dang bai', $worker->fresh()->name);

        $this->actingAs($shop)->patchJson($url, ['name' => ''])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->actingAs($shop)->patchJson($url, ['name' => 'trung-ten'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->actingAs($shop)->patchJson($url, ['name' => str_repeat('a', 101)])->assertUnprocessable();
    }

    public function test_cannot_rename_worker_not_assigned_or_inactive(): void
    {
        $shop = $this->creator();
        [$foreign] = Worker::register('khac');
        $foreign->users()->attach($this->creator()->id);
        [$off] = Worker::register('tat');
        $off->users()->attach($shop->id);
        $off->update(['is_active' => false]);

        $this->actingAs($shop)->patchJson(self::URL."/{$foreign->id}/name", ['name' => 'x'])->assertNotFound();
        $this->actingAs($shop)->patchJson(self::URL."/{$off->id}/name", ['name' => 'x'])->assertNotFound();
        $this->assertSame('khac', $foreign->fresh()->name);
        $this->actingAs($this->buyer())->patchJson(self::URL."/{$foreign->id}/name", ['name' => 'x'])->assertForbidden();
    }

    public function test_shop_regenerates_token_of_assigned_worker(): void
    {
        $shop = $this->creator();
        [$worker, $oldToken] = Worker::register('may-token', Worker::TYPE_IMAGE);
        $worker->users()->attach($shop->id);

        $newToken = $this->actingAs($shop)->postJson(self::URL."/{$worker->id}/token")
            ->assertOk()->assertJsonPath('id', $worker->id)->json('token');

        $this->assertNotSame($oldToken, $newToken);
        $this->assertNull(Worker::findActiveByToken($oldToken));
        $this->assertSame($worker->id, Worker::findActiveByToken($newToken)?->id);
    }

    public function test_cannot_regenerate_token_of_worker_not_assigned_or_inactive(): void
    {
        $shop = $this->creator();
        [$foreign, $foreignToken] = Worker::register('khac-token');
        $foreign->users()->attach($this->creator()->id);
        [$off] = Worker::register('tat-token');
        $off->users()->attach($shop->id);
        $off->update(['is_active' => false]);

        $this->actingAs($shop)->postJson(self::URL."/{$foreign->id}/token")->assertNotFound();
        $this->actingAs($shop)->postJson(self::URL."/{$off->id}/token")->assertNotFound();
        $this->assertSame($foreign->id, Worker::findActiveByToken($foreignToken)?->id);
        $this->actingAs($this->buyer())->postJson(self::URL."/{$foreign->id}/token")->assertForbidden();
    }
}
