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
}
