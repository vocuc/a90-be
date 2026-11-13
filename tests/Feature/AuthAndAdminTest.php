<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthAndAdminTest extends TestCase
{
    public function test_register_login_and_me(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Người mua',
            'email' => 'buyer@example.com',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ])->assertCreated();

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'buyer@example.com',
            'password' => 'secret123',
        ])->assertOk()->json('data.token');

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'buyer@example.com')
            ->assertJsonPath('data.creator_profile', null);
    }

    public function test_wrong_password_is_rejected(): void
    {
        User::factory()->create(['email' => 'a@example.com']);

        $this->postJson('/api/v1/auth/login', ['email' => 'a@example.com', 'password' => 'nope'])
            ->assertStatus(422);
    }

    public function test_banned_user_is_blocked(): void
    {
        $user = $this->buyer();
        $user->forceFill(['status' => 'banned'])->save();

        $this->actingAs($user)->getJson('/api/v1/auth/me')->assertForbidden()->assertJsonPath('code', 'ACCOUNT_BANNED');
    }
}
