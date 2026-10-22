<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
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

    /** Token hợp lệ: qua được xác thực (worker chưa gắn tài khoản đăng bài -> 409, không phải 401). */
    private function assertTokenWorks(string $token): void
    {
        $this->withToken($token)->getJson('/api/v1/publish-jobs')->assertStatus(409);
    }

    private function assertTokenRejected(string $token): void
    {
        $this->withToken($token)->getJson('/api/v1/publish-jobs')->assertStatus(401);
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

        $this->post('/admin/workers', ['name' => '  phone1-tiktok  '])->assertRedirect();
        $worker = Worker::query()->where('name', 'phone1-tiktok')->firstOrFail();
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

        // Bắt buộc tên, không trùng
        $this->post('/admin/workers', ['name' => ''])->assertSessionHasErrors('name');
        $this->post('/admin/workers', ['name' => 'phone1-tiktok'])->assertSessionHasErrors('name');
        $this->assertSame(1, Worker::query()->count());
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
        $this->assertSame([$shop->id], $worker->imageUsers()->pluck('users.id')->all());
        $this->get("/admin/workers/{$worker->id}")->assertOk()->assertSee($shop->email);
        $this->get('/admin/workers')->assertOk()->assertSee('1 tài khoản');
        $this->assertTrue(AuditLog::query()->where('action', 'admin.worker.attach_user')->exists());

        $this->post("/admin/workers/{$worker->id}/users", ['email' => 'khong-co@example.com'])->assertSessionHasErrors('email');
        $this->post("/admin/workers/{$worker->id}/users", ['email' => ''])->assertSessionHasErrors('email');

        $this->delete("/admin/workers/{$worker->id}/users/{$shop->id}")->assertSessionHas('success');
        $this->assertSame(0, $worker->imageUsers()->count());
        $this->assertTrue(AuditLog::query()->where('action', 'admin.worker.detach_user')->exists());
    }
}
