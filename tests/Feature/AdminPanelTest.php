<?php

namespace Tests\Feature;

use App\Models\CreatorProfile;
use App\Models\Generation;
use App\Models\Setting;
use App\Models\User;
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
        $creative = $this->publishedCreative($creator, price: 1000);
        $buyer = $this->buyer(credit: 5000);
        $this->generate($buyer, $creative, quantity: 2)->assertStatus(202);
        $this->runWorker([null, 'CONTENT_BLOCKED']);
        $generation = Generation::query()->firstOrFail();

        $this->actingAs($this->admin());

        $pages = [
            '/admin' => 'Lượt tạo ảnh gần đây',
            '/admin/users' => $buyer->email,
            "/admin/users/{$buyer->id}" => 'Điều chỉnh Credit',
            '/admin/creators' => 'creator'.$creator->id,
            '/admin/creatives' => $creative->title,
            "/admin/creatives/{$creative->id}" => 'Place the product on a marble table.',
            '/admin/generations' => $buyer->email,
            "/admin/generations/{$generation->id}" => 'CONTENT_BLOCKED',
            '/admin/transactions' => 'refunded',
            '/admin/credit-ledger' => 'generation_refund',
            '/admin/revenues' => 'Doanh thu Creator',
            '/admin/settings' => 'Creator nhận mặc định',
            '/admin/audit-logs' => 'generation.create',
        ];

        foreach ($pages as $url => $expected) {
            $this->get($url)->assertOk()->assertSee($expected, false);
        }
    }

    public function test_admin_adjusts_credit_idempotently(): void
    {
        $user = $this->buyer();
        $this->actingAs($this->admin());
        $key = (string) Str::uuid();

        $this->post("/admin/users/{$user->id}/credits", ['direction' => 'add', 'amount' => 20000, 'note' => 'CK', 'idempotency_key' => $key])
            ->assertSessionHas('success');
        // Bấm gửi lại cùng form: không cộng lần 2.
        $this->post("/admin/users/{$user->id}/credits", ['direction' => 'add', 'amount' => 20000, 'note' => 'CK', 'idempotency_key' => $key]);
        $this->assertSame(20000, $this->balance($user));

        $this->post("/admin/users/{$user->id}/credits", ['direction' => 'subtract', 'amount' => 5000, 'note' => 'Sai', 'idempotency_key' => (string) Str::uuid()]);
        $this->assertSame(15000, $this->balance($user));

        // Không cho trừ âm số dư.
        $this->post("/admin/users/{$user->id}/credits", ['direction' => 'subtract', 'amount' => 999999, 'note' => 'x', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasErrors('amount');
        $this->assertSame(15000, $this->balance($user));
        $this->assertLedgerMatchesBalance($user);
    }

    public function test_hiding_a_creative_removes_it_from_marketplace(): void
    {
        $creative = $this->publishedCreative($this->creator());
        $this->actingAs($this->admin());

        $this->post("/admin/creatives/{$creative->id}/hide", ['reason' => 'Vi phạm'])->assertSessionHas('success');
        $this->getJson("/api/v1/creatives/{$creative->slug}")->assertNotFound();
        $this->generate($this->buyer(credit: 5000), $creative)->assertStatus(404)->assertJsonPath('code', 'CREATIVE_NOT_AVAILABLE');

        $this->actingAs(User::query()->where('role', 'admin')->firstOrFail());
        $this->post("/admin/creatives/{$creative->id}/unhide")->assertSessionHas('success');
        $this->getJson("/api/v1/creatives/{$creative->slug}")->assertOk();
    }

    public function test_admin_sets_verified_and_custom_creator_share(): void
    {
        $creator = $this->creator();
        $creative = $this->publishedCreative($creator, price: 1000);
        $profile = CreatorProfile::query()->where('user_id', $creator->id)->firstOrFail();
        $this->actingAs($this->admin());

        $this->put("/admin/creators/{$profile->id}", ['verified' => 1, 'creator_share_percent' => 25])->assertSessionHas('success');
        $profile->refresh();
        $this->assertTrue($profile->verified);
        $this->assertSame('25.00', $profile->creator_share_percent);

        // Mức riêng được áp dụng cho Transaction mới: Creator nhận 25%.
        $this->generate($this->buyer(credit: 5000), $creative)->assertStatus(202);
        $this->assertDatabaseHas('transactions', ['creator_share_percent' => 25, 'platform_fee_amount' => 750, 'creator_amount' => 250]);

        // Để trống = quay về mức mặc định.
        $this->actingAs(User::query()->where('role', 'admin')->firstOrFail());
        $this->put("/admin/creators/{$profile->id}", ['verified' => 0, 'creator_share_percent' => ''])->assertSessionHas('success');
        $this->assertNull($profile->fresh()->creator_share_percent);
    }

    public function test_admin_updates_settings(): void
    {
        $this->actingAs($this->admin());

        $payload = [
            'creator_share_percent' => 15, 'revenue_hold_days' => 3, 'default_max_outputs' => 4,
            'max_outputs_limit' => 8, 'input_max_size_kb' => 5120,
        ];
        $this->put('/admin/settings', $payload)->assertSessionHas('success');

        $settings = app(SettingService::class);
        $this->assertSame('15', $settings->get('creator_share_percent'));
        $this->assertSame(8, $settings->int('generation.max_outputs_limit'));
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
