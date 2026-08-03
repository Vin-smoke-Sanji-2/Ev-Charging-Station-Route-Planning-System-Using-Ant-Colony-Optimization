<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTestData;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use CreatesTestData;
    use RefreshDatabase;

    public function test_ev_owner_can_register(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('email', 'alice@example.com')
            ->assertJsonPath('role', 'ev_owner')
            ->assertJsonPath('status', 'active')
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('token');

        $this->assertDatabaseHas('users', ['email' => 'alice@example.com', 'role' => 'ev_owner']);
        $this->assertAuthenticated();
    }

    public function test_station_owner_registration_starts_pending(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'station_owner',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('role', 'station_owner')
            ->assertJsonPath('status', 'pending');
    }

    public function test_register_rejects_duplicate_email(): void
    {
        $this->makeUser(['email' => 'dup@example.com']);

        $response = $this->postJson('/api/auth/register', [
            'name' => 'Dup',
            'email' => 'dup@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $response->assertStatus(422);
    }

    public function test_register_rejects_admin_role_selection(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Wannabe Admin',
            'email' => 'admin-wannabe@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'admin',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('users', ['email' => 'admin-wannabe@example.com']);
    }

    public function test_user_can_login_with_correct_credentials(): void
    {
        $user = $this->makeUser(['email' => 'login@example.com', 'password' => 'secret123']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'login@example.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('email', 'login@example.com')
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('token');

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $this->makeUser(['email' => 'login2@example.com', 'password' => 'secret123']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'login2@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401)->assertJson(['message' => 'Invalid credentials']);
        $this->assertGuest();
    }

    public function test_me_returns_current_user_when_authenticated(): void
    {
        $user = $this->makeUser();

        $response = $this->actingAs($user)->getJson('/api/auth/me');

        $response->assertStatus(200)->assertJsonPath('id', $user->id)->assertJsonPath('email', $user->email);
    }

    public function test_me_returns_401_when_unauthenticated(): void
    {
        $response = $this->getJson('/api/auth/me');

        $response->assertStatus(401);
    }

    public function test_logout_invalidates_the_session(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $this->getJson('/api/auth/me')->assertStatus(200);

        $this->postJson('/api/auth/logout')
            ->assertStatus(200)
            ->assertJson(['message' => 'Logged out']);

        // The 'web' guard (the one AuthController actually logs out) must
        // no longer report an authenticated user. We deliberately don't use
        // assertGuest() here: the auth:sanctum middleware switches the auth
        // manager's *default* guard to 'sanctum' for the duration of the
        // request, and that RequestGuard caches the pre-logout user, so
        // checking the unqualified default guard gives a false positive.
        $this->assertGuest('web');

        // Laravel's RequestGuard (which auth:sanctum resolves to) caches the
        // user it resolved on the *first* authenticated request within this
        // test and doesn't re-check on later simulated requests sharing the
        // same container. A real browser gets a fresh container per request,
        // so this cache never lingers in production - only here.
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/auth/me')->assertStatus(401);
    }
}
