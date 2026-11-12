<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\SocialPost;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminWorkerTest extends TestCase
{
    private function admin(): User
    {
        $admin = $this->buyer();
        $admin->forceFill(['role' => 'admin'])->save();

        return $admin;
    }

    /** Token hợp lệ: qua được xác thực (worker tạo ảnh chưa gắn tài khoản -> 204 chưa có job, không phải 401). */
    private function assertTokenWorks(string $token): void
    {
        $this->withToken($token)->getJson('/api/v1/jobs')->assertNoContent();
    }

    private function assertTokenRejected(string $token): void
    {
        $this->withToken($token)->getJson('/api/v1/jobs')->assertStatus(401);
    }

    public function test_only_admin_can_open_worker_pages(): void
    {
        $this->get('/admin/workers')->assertRedirect('/admin/login');
        $this->actingAs($this->buyer())->get('/admin/workers')->assertRedirect('/admin/login');
    }

    public function test_admin_creates_worker_and_views_its_token(): void
    {
        $this->actingAs($this->admin());
        $this->get('/admin/workers')->assertOk()->assertSee('Chưa có worker nào');
        $this->get('/admin/workers/create')->assertOk();

        $shop = $this->buyer();
        $this->post('/admin/workers', ['name' => '  phone1-tiktok  ', 'type' => 'image', 'email' => $shop->email])->assertRedirect();
        $worker = Worker::query()->where('name', 'phone1-tiktok')->firstOrFail();
        $this->assertSame(Worker::TYPE_IMAGE, $worker->type);
        $this->assertSame([$shop->id], $worker->users()->pluck('users.id')->all());
        $token = $worker->plainToken();
        $this->assertNotNull($token);
        // DB lưu bản mã hoá, không lưu token thô
        $this->assertNotSame($token, DB::table('workers')->where('id', $worker->id)->value('token_encrypted'));
        $this->assertSame(Worker::hashToken($token), $worker->token_hash);

        $this->get("/admin/workers/{$worker->id}")->assertOk()->assertSee($token);
        $this->get('/admin/workers')->assertOk()->assertSee('phone1-tiktok')->assertDontSee($token);
        $this->assertTokenWorks($token);
        $this->assertTrue(AuditLog::query()->where('action', 'admin.worker.create')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'admin.worker.view_token')->exists());

        // Bắt buộc tên (không trùng) và loại; email phải có thật
        $this->post('/admin/workers', ['name' => '', 'type' => 'image'])->assertSessionHasErrors('name');
        $this->post('/admin/workers', ['name' => 'phone1-tiktok', 'type' => 'image'])->assertSessionHasErrors('name');
        $this->post('/admin/workers', ['name' => 'moi'])->assertSessionHasErrors('type');
        $this->post('/admin/workers', ['name' => 'moi', 'type' => 'video'])->assertSessionHasErrors('type');
        $this->post('/admin/workers', ['name' => 'moi', 'type' => 'publish', 'email' => 'khong-co@a51.test'])->assertSessionHasErrors('email');
        // Không gắn tài khoản lúc tạo cũng được
        $this->post('/admin/workers', ['name' => 'dang-bai', 'type' => 'publish'])->assertRedirect();
        $this->assertSame(Worker::TYPE_PUBLISH, Worker::query()->where('name', 'dang-bai')->value('type'));
        $this->assertSame(2, Worker::query()->count());
    }

    public function test_admin_renames_worker_without_touching_token(): void
    {
        [$worker, $token] = Worker::register('phone1-tiktok');
        Worker::register('phone2-tiktok');
        $this->actingAs($this->admin());

        $this->patch("/admin/workers/{$worker->id}/name", ['name' => '  phone1-facebook  '])->assertRedirect();
        $this->assertSame('phone1-facebook', $worker->fresh()->name);
        $this->assertTokenWorks($token);
        $this->assertTrue(AuditLog::query()->where('action', 'admin.worker.rename')->exists());

        // Bắt buộc tên, không trùng worker khác; giữ nguyên tên của chính nó thì được
        $this->patch("/admin/workers/{$worker->id}/name", ['name' => ''])->assertSessionHasErrors('name');
        $this->patch("/admin/workers/{$worker->id}/name", ['name' => 'phone2-tiktok'])->assertSessionHasErrors('name');
        $this->patch("/admin/workers/{$worker->id}/name", ['name' => 'phone1-facebook'])->assertSessionHasNoErrors();
        $this->assertSame('phone1-facebook', $worker->fresh()->name);
    }

    public function test_regenerate_token_invalidates_old_token(): void
    {
        [$worker, $old] = Worker::register('phone1-facebook');
        $this->actingAs($this->admin());

        $this->post("/admin/workers/{$worker->id}/regenerate")->assertRedirect("/admin/workers/{$worker->id}");
        $new = $worker->fresh()->plainToken();
        $this->assertNotSame($old, $new);
        $this->assertTokenRejected($old);
        $this->assertTokenWorks($new);
        $this->get("/admin/workers/{$worker->id}")->assertOk()->assertSee($new)->assertDontSee($old);
    }

    public function test_worker_created_before_encrypted_token_must_regenerate_to_view(): void
    {
        [$worker, $old] = Worker::register('may-cu');
        $worker->forceFill(['token_encrypted' => null])->save();
        $this->actingAs($this->admin());

        $this->get("/admin/workers/{$worker->id}")->assertOk()->assertSee('không xem lại được');
        $this->assertTokenWorks($old);

        $this->post("/admin/workers/{$worker->id}/regenerate");
        $this->get("/admin/workers/{$worker->id}")->assertOk()->assertSee($worker->fresh()->plainToken());
    }

    public function test_token_encrypted_with_another_app_key_does_not_break_worker_page(): void
    {
        [$worker, $old] = Worker::register('db-chep-tu-may-khac');
        // Token mã hoá bằng APP_KEY khác: giải mã lỗi "The MAC is invalid"
        $foreign = (new \Illuminate\Encryption\Encrypter(random_bytes(32), 'aes-256-cbc'))->encryptString($old);
        \Illuminate\Support\Facades\DB::table('workers')->where('id', $worker->id)->update(['token_encrypted' => $foreign]);
        $this->actingAs($this->admin());

        $this->get("/admin/workers/{$worker->id}")->assertOk()->assertSee('không xem lại được');
        $this->assertTokenWorks($old);

        // Tạo token mới vẫn được (save() không giải mã token cũ), token mới xem lại được
        $this->post("/admin/workers/{$worker->id}/regenerate")->assertRedirect("/admin/workers/{$worker->id}");
        $new = $worker->fresh()->plainToken();
        $this->assertNotNull($new);
        $this->get("/admin/workers/{$worker->id}")->assertOk()->assertSee($new);
        $this->assertTokenRejected($old);
        $this->assertTokenWorks($new);
    }

    public function test_disabled_worker_is_rejected_until_enabled(): void
    {
        [$worker, $token] = Worker::register('phone2-tiktok');
        $this->actingAs($this->admin());

        $this->post("/admin/workers/{$worker->id}/toggle")->assertSessionHas('success');
        $this->assertFalse($worker->fresh()->is_active);
        $this->assertTokenRejected($token);

        $this->post("/admin/workers/{$worker->id}/toggle");
        $this->assertTrue($worker->fresh()->is_active);
        $this->assertTokenWorks($token);
    }

    public function test_admin_attaches_and_detaches_image_job_users(): void
    {
        [$worker] = Worker::register('may-tao-anh');
        $shop = $this->buyer();
        $this->actingAs($this->admin());

        $this->get("/admin/workers/{$worker->id}")->assertOk()->assertSee('Chưa gắn tài khoản nào');

        $this->post("/admin/workers/{$worker->id}/users", ['email' => "  {$shop->email} "])->assertSessionHas('success');
        // Gắn lại không bị trùng
        $this->post("/admin/workers/{$worker->id}/users", ['email' => $shop->email])->assertSessionHas('success');
        $this->assertSame([$shop->id], $worker->users()->pluck('users.id')->all());
        $this->get("/admin/workers/{$worker->id}")->assertOk()->assertSee($shop->email);
        $this->get('/admin/workers')->assertOk()->assertSee($shop->email)->assertSee('Tạo ảnh');
        $this->assertTrue(AuditLog::query()->where('action', 'admin.worker.attach_user')->exists());

        $this->post("/admin/workers/{$worker->id}/users", ['email' => 'khong-co@example.com'])->assertSessionHasErrors('email');
        $this->post("/admin/workers/{$worker->id}/users", ['email' => ''])->assertSessionHasErrors('email');

        $this->delete("/admin/workers/{$worker->id}/users/{$shop->id}")->assertSessionHas('success');
        $this->assertSame(0, $worker->users()->count());
        $this->assertTrue(AuditLog::query()->where('action', 'admin.worker.detach_user')->exists());
    }

    public function test_worker_only_does_jobs_of_its_type(): void
    {
        [$image, $imageToken] = Worker::register('anh', Worker::TYPE_IMAGE);
        [$publish, $publishToken] = Worker::register('dang', Worker::TYPE_PUBLISH);

        $this->withToken($imageToken)->getJson('/api/v1/post-jobs')->assertStatus(409)->assertJsonPath('code', 'WORKER_TYPE_MISMATCH');
        $this->withToken($imageToken)->getJson('/api/v1/video-images?count=1')->assertStatus(409)->assertJsonPath('code', 'WORKER_TYPE_MISMATCH');
        $this->withToken($publishToken)->getJson('/api/v1/jobs')->assertStatus(409)->assertJsonPath('code', 'WORKER_TYPE_MISMATCH');
        $this->withToken($imageToken)->getJson('/api/v1/jobs')->assertNoContent();
        $this->withToken($publishToken)->getJson('/api/v1/post-jobs')->assertNoContent();
    }

    public function test_change_type_and_detach_blocked_while_worker_has_pending_jobs(): void
    {
        [$worker, $token] = Worker::register('may-1', Worker::TYPE_PUBLISH);
        $shop = $this->buyer(credit: 100000);
        $worker->users()->attach($shop->id);
        $post = SocialPost::query()->create([
            'user_id' => $shop->id, 'type' => 'photo', 'status' => 'queued', 'source' => 'batch', 'worker_id' => $worker->id,
            'platforms' => ['tiktok'], 'tiktok_status' => 'queued', 'scheduled_at' => now()->addDay(), 'idempotency_key' => 'k',
        ]);
        $this->actingAs($this->admin());

        // Còn bài chờ đăng: không đổi loại, không bỏ shop
        $this->patch("/admin/workers/{$worker->id}/type", ['type' => 'image'])->assertSessionHasErrors('worker');
        $this->delete("/admin/workers/{$worker->id}/users/{$shop->id}")->assertSessionHasErrors('worker');
        $this->assertSame(Worker::TYPE_PUBLISH, $worker->fresh()->type);
        $this->assertSame(1, $worker->users()->count());

        // Bài đã đăng xong: đổi được
        $post->update(['status' => 'published', 'tiktok_status' => 'published']);
        $this->patch("/admin/workers/{$worker->id}/type", ['type' => 'image'])->assertSessionHas('success');
        $this->assertSame(Worker::TYPE_IMAGE, $worker->fresh()->type);
        $this->patch("/admin/workers/{$worker->id}/type", ['type' => 'x'])->assertSessionHasErrors('type');
        $this->assertTrue(AuditLog::query()->where('action', 'admin.worker.change_type')->exists());

        // Worker tạo ảnh đang tạo ảnh của shop: không bỏ shop được
        $this->withToken($token)->getJson('/api/v1/jobs')->assertNoContent();
        $this->generate($shop, $this->publishedCreative($this->creator()))->assertStatus(202);
        $this->withToken($token)->getJson('/api/v1/jobs')->assertOk();
        $this->actingAs($this->admin())->delete("/admin/workers/{$worker->id}/users/{$shop->id}")->assertSessionHasErrors('worker');
    }
}
