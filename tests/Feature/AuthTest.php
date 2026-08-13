<?php

namespace Tests\Feature;

use App\Mail\LoginOtpMail;
use App\Models\LoginOtp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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
     * Station-owner registration creates two independent admin action
     * items - the new account (reviewed on Station Owners) and the new
     * station (reviewed on Stations) - so both notifications should exist,
     * not just one covering "something new happened."
     */
    public function test_station_owner_registration_notifies_every_admin(): void
    {
        $adminA = $this->makeAdmin();
        $adminB = $this->makeAdmin();
        $evOwner = $this->makeUser(); // must never receive an admin notification

        $this->postJson('/api/auth/register', [
            'name' => 'Bob',
            'email' => 'bob@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'station_owner',
            'station' => [
                'name' => "Bob's Charging Station",
                'latitude' => 16.85,
                'longitude' => 96.20,
            ],
        ])->assertStatus(201);

        foreach ([$adminA, $adminB] as $admin) {
            $this->assertDatabaseHas('user_notifications', ['user_id' => $admin->id, 'type' => 'Registration']);
            $this->assertDatabaseHas('user_notifications', ['user_id' => $admin->id, 'type' => 'Station']);
        }
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $evOwner->id]);
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

    /**
     * station_owner and admin both require an OTP step (see
     * AuthController::OTP_REQUIRED_ROLES) - a correct password alone
     * unlocks that step, it never establishes a session by itself.
     * ev_owner is deliberately exempt (changed 2026-08-13 - originally
     * the reverse: ev_owner+station_owner required it, admin didn't).
     */
    public function test_login_with_correct_credentials_requires_otp_for_station_owner(): void
    {
        Mail::fake();
        $this->makeStationOwner(['email' => 'login@example.com', 'password' => 'secret123']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'login@example.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('otp_required', true)
            ->assertJsonPath('email', 'login@example.com')
            ->assertJsonMissingPath('password');

        $this->assertGuest();
        Mail::assertSent(LoginOtpMail::class, fn ($mail) => $mail->hasTo('login@example.com'));
    }

    public function test_login_fails_with_wrong_password(): void
    {
        Mail::fake();
        $this->makeUser(['email' => 'login2@example.com', 'password' => 'secret123']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'login2@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401)->assertJson(['message' => 'Invalid credentials']);
        $this->assertGuest();
        Mail::assertNothingSent();
    }

    public function test_ev_owner_login_skips_otp_and_authenticates_immediately(): void
    {
        Mail::fake();
        $user = $this->makeUser(['email' => 'ev-owner-login@example.com', 'password' => 'secret123']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'ev-owner-login@example.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('email', 'ev-owner-login@example.com')
            ->assertJsonMissingPath('otp_required');

        $this->assertAuthenticatedAs($user);
        Mail::assertNothingSent();
    }

    public function test_admin_login_requires_otp(): void
    {
        Mail::fake();
        $this->makeAdmin(['email' => 'admin-login@example.com', 'password' => 'secret123']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'admin-login@example.com',
            'password' => 'secret123',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('otp_required', true)
            ->assertJsonPath('email', 'admin-login@example.com');

        $this->assertGuest();
        Mail::assertSent(LoginOtpMail::class, fn ($mail) => $mail->hasTo('admin-login@example.com'));
    }

    /**
     * Local-dev convenience: SendGrid's API accepts a send to a fake/
     * nonexistent address without complaint, so there's otherwise no way
     * to retrieve a code sent to a made-up test address. Only fires in
     * 'local' - never in any other environment, since an OTP code is a
     * real credential that must not leak into a real deployment's logs.
     */
    public function test_otp_code_is_logged_in_local_environment(): void
    {
        Mail::fake();
        Log::spy();
        // Overriding the app's env has a real side effect worth noting:
        // Laravel's CSRF middleware exempts requests via
        // runningUnitTests() (env === 'testing'), so forcing 'local'
        // here makes it start enforcing real CSRF tokens the test client
        // never set up - disabled explicitly to isolate this test to just
        // the one thing it's actually checking.
        app()->instance('env', 'local');
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
        $this->makeAdmin(['email' => 'local-log@example.com', 'password' => 'secret123']);

        $this->postJson('/api/auth/login', [
            'email' => 'local-log@example.com',
            'password' => 'secret123',
        ])->assertStatus(200);

        Log::shouldHaveReceived('info')
            ->once()
            ->with(\Mockery::pattern('/local-log@example\.com.*\d{6}/'));
    }

    public function test_otp_code_is_not_logged_outside_local_environment(): void
    {
        Mail::fake();
        Log::spy();
        // phpunit.xml sets APP_ENV=testing for the whole suite - this
        // assertion is really just making that assumption explicit.
        $this->assertSame('testing', app()->environment());
        $this->makeAdmin(['email' => 'no-log@example.com', 'password' => 'secret123']);

        $this->postJson('/api/auth/login', [
            'email' => 'no-log@example.com',
            'password' => 'secret123',
        ])->assertStatus(200);

        Log::shouldNotHaveReceived('info');
    }

    public function test_full_otp_login_flow_authenticates_the_user(): void
    {
        Mail::fake();
        $admin = $this->makeAdmin(['email' => 'otp-flow@example.com', 'password' => 'secret123']);

        $this->postJson('/api/auth/login', [
            'email' => 'otp-flow@example.com',
            'password' => 'secret123',
        ])->assertStatus(200)->assertJsonPath('otp_required', true);

        $this->assertGuest();

        $code = null;
        Mail::assertSent(LoginOtpMail::class, function ($mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        $response = $this->postJson('/api/auth/verify-otp', [
            'email' => 'otp-flow@example.com',
            'code' => $code,
        ]);

        $response->assertStatus(200)->assertJsonPath('email', 'otp-flow@example.com');
        $this->assertAuthenticatedAs($admin);
    }

    public function test_verify_otp_with_wrong_code_is_rejected(): void
    {
        Mail::fake();
        $this->makeStationOwner(['email' => 'otp-wrong@example.com', 'password' => 'secret123']);

        $this->postJson('/api/auth/login', [
            'email' => 'otp-wrong@example.com',
            'password' => 'secret123',
        ]);

        $response = $this->postJson('/api/auth/verify-otp', [
            'email' => 'otp-wrong@example.com',
            'code' => '000000',
        ]);

        $response->assertStatus(422);
        $this->assertGuest();
    }

    public function test_verify_otp_with_expired_code_is_rejected(): void
    {
        $user = $this->makeUser(['email' => 'otp-expired@example.com']);
        $otp = LoginOtp::generateFor($user);
        $user->loginOtps()->latest()->first()->update(['expires_at' => now()->subMinute()]);

        $response = $this->postJson('/api/auth/verify-otp', [
            'email' => 'otp-expired@example.com',
            'code' => $otp,
        ]);

        $response->assertStatus(422);
        $this->assertGuest();
    }

    public function test_a_code_cannot_be_used_twice(): void
    {
        $user = $this->makeUser(['email' => 'otp-reuse@example.com']);
        $code = LoginOtp::generateFor($user);

        $this->postJson('/api/auth/verify-otp', [
            'email' => 'otp-reuse@example.com',
            'code' => $code,
        ])->assertStatus(200);

        $this->postJson('/api/auth/logout');

        $response = $this->postJson('/api/auth/verify-otp', [
            'email' => 'otp-reuse@example.com',
            'code' => $code,
        ]);

        $response->assertStatus(422);
    }

    public function test_resend_otp_sends_a_new_code(): void
    {
        Mail::fake();
        $this->makeStationOwner(['email' => 'otp-resend@example.com', 'password' => 'secret123']);

        $this->postJson('/api/auth/login', [
            'email' => 'otp-resend@example.com',
            'password' => 'secret123',
        ]);

        $this->postJson('/api/auth/resend-otp', ['email' => 'otp-resend@example.com'])
            ->assertStatus(200);

        Mail::assertSent(LoginOtpMail::class, 2);
    }

    /**
     * Deliberately identical response whether the account exists, doesn't
     * exist, or doesn't need OTP - this endpoint must not be usable to
     * probe which emails are registered.
     */
    public function test_resend_otp_gives_the_same_response_for_a_nonexistent_email(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/auth/resend-otp', ['email' => 'nobody@example.com']);

        $response->assertStatus(200);
        Mail::assertNothingSent();
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
