<?php

namespace Tests\Feature;

use App\Models\CreatorProfile;
use App\Models\Generation;
use App\Models\Setting;
use App\Models\User;
use App\Models\Worker;
use App\Services\SettingService;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    private function admin(): User
    {
        $admin = $this->buyer();
        $admin->forceFill(['role' => 'admin'])->save();

        return $admin;
    }

    public function test_guests_are_redirected_to_login_and_non_admins_cannot_log_in(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/login')->assertOk()->assertSee('Đăng nhập trang quản trị');

        $user = User::factory()->create(['password' => 'secret123']);
        $this->post('/admin/login', ['email' => $user->email, 'password' => 'secret123'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        // Đã đăng nhập bằng tài khoản thường (ví dụ qua session khác) cũng bị chặn.
        $this->actingAs($user)->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_admin_can_log_in_and_log_out(): void
    {
        $admin = User::factory()->create(['password' => 'secret123']);
        $admin->forceFill(['role' => 'admin'])->save();

        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'secret123'])
            ->assertRedirect('/admin');
        $this->assertAuthenticatedAs($admin);

        $this->post('/admin/logout')->assertRedirect('/admin/login');
        $this->assertGuest();
    }

    public function test_every_admin_page_renders_with_data(): void
    {
        $creator = $this->creator();
        $creative = $this->publishedCreative($creator);
        $buyer = $this->buyer();
        $this->generate($buyer, $creative, quantity: 2)->assertStatus(202);
        $this->runWorker([null, 'CONTENT_BLOCKED']);
        $generation = Generation::query()->firstOrFail();

        $this->actingAs($this->admin());

        $pages = [
            '/admin' => 'Lượt tạo ảnh gần đây',
            '/admin/users' => $buyer->email,
            "/admin/users/{$buyer->id}" => 'Lượt tạo ảnh gần đây',
            '/admin/creators' => 'creator'.$creator->id,
            '/admin/creatives' => $creative->title,
            "/admin/creatives/{$creative->id}" => 'Place the product on a marble table.',
            '/admin/generations' => $buyer->email,
            "/admin/generations/{$generation->id}" => 'CONTENT_BLOCKED',
            '/admin/settings' => 'Số ảnh tối đa mặc định',
            '/admin/audit-logs' => 'generation.create',
        ];

        foreach ($pages as $url => $expected) {
            $this->get($url)->assertOk()->assertSee($expected, false);
        }
    }

    public function test_admin_assigns_post_shop_to_worker_and_old_pages_are_gone(): void
    {
        [$worker] = Worker::register('may-1', Worker::TYPE_PUBLISH);
        $shop = $this->creator();
        $this->actingAs($this->admin());

        $this->post("/admin/workers/{$worker->id}/users", ['email' => $shop->email])->assertSessionHas('success');
        $this->get('/admin/workers')->assertOk()->assertSee('Đăng bài')->assertSee($shop->email);
        $this->get("/admin/workers/{$worker->id}")->assertOk()->assertSee('Tài khoản được gắn');

        // Chủ shop chọn được worker này để đăng bài
        $this->actingAs($shop)->getJson('/api/v1/creator/workers')->assertOk()->assertJsonPath('data.0.functions', ['publish', 'video']);

        // Trang Tài khoản đăng bài / tra trạng thái TikTok đã bỏ
        $this->actingAs($this->admin());
        $this->get('/admin/publish-accounts')->assertNotFound();
        $this->get('/admin/tiktok-status')->assertNotFound();
    }

    public function test_hiding_a_creative_removes_it_from_marketplace(): void
    {
        $creative = $this->publishedCreative($this->creator());
        $this->actingAs($this->admin());

        $this->post("/admin/creatives/{$creative->id}/hide", ['reason' => 'Vi phạm'])->assertSessionHas('success');
        $this->getJson("/api/v1/creatives/{$creative->slug}")->assertNotFound();
        $this->generate($this->buyer(), $creative)->assertStatus(404)->assertJsonPath('code', 'CREATIVE_NOT_AVAILABLE');

        $this->actingAs(User::query()->where('role', 'admin')->firstOrFail());
        $this->post("/admin/creatives/{$creative->id}/unhide")->assertSessionHas('success');
        $this->getJson("/api/v1/creatives/{$creative->slug}")->assertOk();
    }

    public function test_admin_sets_verified(): void
    {
        $creator = $this->creator();
        $profile = CreatorProfile::query()->where('user_id', $creator->id)->firstOrFail();
        $this->actingAs($this->admin());

        $this->put("/admin/creators/{$profile->id}", ['verified' => 1])->assertSessionHas('success');
        $this->assertTrue($profile->fresh()->verified);

        $this->put("/admin/creators/{$profile->id}", ['verified' => 0])->assertSessionHas('success');
        $this->assertFalse($profile->fresh()->verified);
    }

    public function test_admin_updates_settings(): void
    {
        $this->actingAs($this->admin());

        $payload = [
            'default_max_outputs' => 4,
            'max_outputs_limit' => 8, 'input_max_size_kb' => 5120, 'max_hidden_images' => 3,
        ];
        $this->put('/admin/settings', $payload)->assertSessionHas('success');

        $settings = app(SettingService::class);
        $this->assertSame(8, $settings->int('generation.max_outputs_limit'));
        $this->assertSame(3, $settings->int('generation.max_hidden_images'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.settings.update']);

        $this->put('/admin/settings', ['default_max_outputs' => 9] + $payload)->assertSessionHasErrors('default_max_outputs');
        $this->assertSame('4', Setting::query()->where('key', 'generation.default_max_outputs')->value('value'));
    }

    public function test_banning_user_revokes_api_tokens(): void
    {
        $user = $this->buyer();
        $token = $user->createToken('api')->plainTextToken;
        $admin = $this->admin();

        $this->actingAs($admin)->post("/admin/users/{$user->id}/toggle-ban")->assertSessionHas('success');
        $this->assertSame('banned', $user->fresh()->status->value);
        $this->assertSame(0, $user->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();

        // Không khóa được admin.
        $this->actingAs($admin)->post("/admin/users/{$admin->id}/toggle-ban")->assertSessionHasErrors('status');
    }

    public function test_admin_grants_and_revokes_admin_role(): void
    {
        $user = $this->buyer();
        $admin = $this->admin();

        $this->actingAs($admin)->post("/admin/users/{$user->id}/toggle-admin")->assertSessionHas('success');
        $this->assertTrue($user->fresh()->isAdmin());
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.user.grant_admin', 'resource_id' => $user->id]);
        $this->actingAs($user->fresh())->get('/admin')->assertOk();

        $this->actingAs($admin)->post("/admin/users/{$user->id}/toggle-admin")->assertSessionHas('success');
        $this->assertFalse($user->fresh()->isAdmin());
        $this->actingAs($user->fresh())->get('/admin')->assertRedirect('/admin/login');

        // Không tự đổi quyền của mình.
        $this->actingAs($admin)->post("/admin/users/{$admin->id}/toggle-admin")->assertSessionHasErrors('role');
        $this->assertTrue($admin->fresh()->isAdmin());

        // Không cấp admin cho tài khoản bị khóa.
        $user->forceFill(['status' => 'banned'])->save();
        $this->actingAs($admin)->post("/admin/users/{$user->id}/toggle-admin")->assertSessionHasErrors('role');
        $this->assertFalse($user->fresh()->isAdmin());
    }
}
