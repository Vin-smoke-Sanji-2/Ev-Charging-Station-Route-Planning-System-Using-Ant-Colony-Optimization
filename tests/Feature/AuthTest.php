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

    /**
     * Station owner registration now collects the owner's first station in
     * the same request - not deferred to a later step. Confirms both rows
     * are created together, the user starts 'pending' (unchanged), the
     * station starts 'pending' verification, and - the Part 1 fix this
     * exercises end-to-end - the station is genuinely linked into the road
     * graph via road_node_id, not left null the way a plain ChargingStation
     * insert used to.
     */
    public function test_station_owner_registration_creates_the_user_and_their_first_station_together(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'station_owner',
            'station' => [
                'name' => "Bob's Charging Station",
                'latitude' => 16.85,
                'longitude' => 96.20,
                'address' => '1 Test Ave',
                'township' => 'Kyauktada',
                'charging_speed' => 'fast',
                'operating_hours' => '24/7',
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('role', 'station_owner')
            ->assertJsonPath('status', 'pending');

        $user = \App\Models\User::where('email', 'bob@example.com')->firstOrFail();
        $this->assertDatabaseHas('charging_stations', [
            'name' => "Bob's Charging Station",
            'owner_user_id' => $user->id,
            'verification_status' => 'pending',
        ]);

        $station = \App\Models\ChargingStation::where('owner_user_id', $user->id)->firstOrFail();
        $this->assertNotNull($station->road_node_id);
        $this->assertDatabaseHas('road_nodes', [
            'id' => $station->road_node_id,
            'type' => 'station',
            'name' => "Bob's Charging Station",
        ]);
    }

    /**
     * The transactional-safety requirement: if the station half of the
     * payload fails validation, registration must fail as a whole - no
     * orphaned user account with no station.
     */
    public function test_station_owner_registration_without_station_data_fails_and_creates_nothing(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Orphan',
            'email' => 'orphan@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'station_owner',
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('users', ['email' => 'orphan@example.com']);
    }

    public function test_station_owner_registration_with_incomplete_station_data_fails_and_creates_nothing(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Half Baked',
            'email' => 'half-baked@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'station_owner',
            'station' => [
                // Missing required 'name'/'latitude'/'longitude'.
                'address' => 'Nowhere Rd',
            ],
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('users', ['email' => 'half-baked@example.com']);
        $this->assertDatabaseMissing('charging_stations', ['address' => 'Nowhere Rd']);
    }

    /** EV owner registration is unaffected - station fields are never required/consulted for it, even if present. */
    public function test_ev_owner_registration_ignores_any_station_data(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Regular EV Owner',
            'email' => 'ev-owner-with-noise@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'station' => ['name' => 'Should be ignored'],
        ]);

        $response->assertStatus(201)->assertJsonPath('role', 'ev_owner');
        $this->assertDatabaseMissing('charging_stations', ['name' => 'Should be ignored']);
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
