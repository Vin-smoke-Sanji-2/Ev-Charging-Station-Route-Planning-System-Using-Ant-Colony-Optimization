<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTestData;
use Tests\TestCase;

class StationOwnerApprovalTest extends TestCase
{
    use CreatesTestData;
    use RefreshDatabase;

    private function stationPayload(): array
    {
        return ['name' => 'Station', 'latitude' => 16.8, 'longitude' => 96.15];
    }

    public function test_pending_station_owner_is_blocked_with_pending_message(): void
    {
        $owner = $this->makeStationOwner(['status' => 'pending']);

        $this->actingAs($owner)->postJson('/api/stations', $this->stationPayload())
            ->assertStatus(403)
            ->assertJson(['message' => 'Your station owner account is pending admin approval.']);
    }

    public function test_rejected_station_owner_is_blocked_with_rejected_message(): void
    {
        $owner = $this->makeStationOwner(['status' => 'rejected']);

        $this->actingAs($owner)->postJson('/api/stations', $this->stationPayload())
            ->assertStatus(403)
            ->assertJson(['message' => 'Your station owner application was not approved.']);
    }

    public function test_suspended_station_owner_is_blocked_with_suspended_message(): void
    {
        $owner = $this->makeStationOwner(['status' => 'suspended']);

        $this->actingAs($owner)->postJson('/api/stations', $this->stationPayload())
            ->assertStatus(403)
            ->assertJson(['message' => 'Your station owner account has been suspended.']);
    }

    public function test_pending_and_rejected_and_suspended_messages_are_all_distinct(): void
    {
        $pending = $this->makeStationOwner(['status' => 'pending'])->stationOwnerAccessDeniedMessage();
        $rejected = $this->makeStationOwner(['status' => 'rejected'])->stationOwnerAccessDeniedMessage();
        $suspended = $this->makeStationOwner(['status' => 'suspended'])->stationOwnerAccessDeniedMessage();

        $this->assertNotSame($pending, $rejected);
        $this->assertNotSame($pending, $suspended);
        $this->assertNotSame($rejected, $suspended);
    }

    public function test_active_station_owner_is_unaffected(): void
    {
        $owner = $this->makeStationOwner(['status' => 'active']);

        $this->actingAs($owner)->postJson('/api/stations', $this->stationPayload())
            ->assertStatus(201);
    }

    public function test_station_owner_who_does_not_own_the_session_station_is_forbidden(): void
    {
        $sessionUser = $this->makeUser();
        $realOwner = $this->makeStationOwner();
        $otherOwner = $this->makeStationOwner();
        $station = $this->makeStation($realOwner);
        $session = $this->makeSession($station, $sessionUser, ['status' => 'waiting']);

        $this->actingAs($otherOwner)->putJson("/api/sessions/{$session->id}", [
            'status' => 'cancelled',
        ])->assertStatus(403);
    }

    public function test_pending_station_owner_cannot_update_their_own_stations_session(): void
    {
        $sessionUser = $this->makeUser();
        $owner = $this->makeStationOwner(['status' => 'pending']);
        $station = $this->makeStation($owner);
        $session = $this->makeSession($station, $sessionUser, ['status' => 'waiting']);

        $this->actingAs($owner)->putJson("/api/sessions/{$session->id}", [
            'status' => 'cancelled',
        ])->assertStatus(403)
            ->assertJson(['message' => 'Your station owner account is pending admin approval.']);
    }

    /**
     * Switch which user the test client acts as. Plain actingAs() isn't
     * enough here: this test's first request is a *real* register() call,
     * which really logs the owner in via the session (not just the
     * actingAs() in-memory shortcut). Sanctum's AuthenticateSession
     * middleware records that owner's password hash in the session on each
     * stateful request, and on the next request compares it against
     * whichever user is currently resolved - once we actingAs($admin), that
     * comparison mismatches and Sanctum treats it as a hijacked session,
     * flushing it and throwing "Unauthenticated." (401) instead of letting
     * the request through. Forgetting the cached guards and flushing the
     * session on every switch keeps the real session in sync with whoever
     * we're pretending to be.
     */
    private function switchActingUser(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->flush();
        $this->actingAs($user);
    }

    public function test_full_station_owner_lifecycle_pending_rejected_then_active(): void
    {
        $admin = $this->makeAdmin();

        $registerResponse = $this->postJson('/api/auth/register', [
            'name' => 'New Owner',
            'email' => 'newowner@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'station_owner',
        ]);
        $registerResponse->assertStatus(201)->assertJsonPath('status', 'pending');
        $owner = User::find($registerResponse->json('id'));

        // Pending: blocked from creating a station.
        $this->actingAs($owner)->postJson('/api/stations', $this->stationPayload())
            ->assertStatus(403)
            ->assertJson(['message' => 'Your station owner account is pending admin approval.']);

        // Admin rejects the application.
        $this->switchActingUser($admin);
        $this->putJson("/api/admin/users/{$owner->id}/status", ['status' => 'rejected'])
            ->assertStatus(200)
            ->assertJsonPath('status', 'rejected');

        // Still blocked, now with the rejected-specific message. $owner is
        // refreshed from the DB first - the admin's update above happened
        // on a separate PHP object, so our in-memory copy is stale.
        $this->switchActingUser($owner->refresh());
        $this->postJson('/api/stations', $this->stationPayload())
            ->assertStatus(403)
            ->assertJson(['message' => 'Your station owner application was not approved.']);

        // Admin reverses course and approves the owner.
        $this->switchActingUser($admin);
        $this->putJson("/api/admin/users/{$owner->id}/status", ['status' => 'active'])
            ->assertStatus(200)
            ->assertJsonPath('status', 'active');

        // Now allowed.
        $this->switchActingUser($owner->refresh());
        $this->postJson('/api/stations', $this->stationPayload())
            ->assertStatus(201);
    }

    public function test_ev_owner_status_is_never_checked_against_station_owner_rules(): void
    {
        $evOwner = $this->makeUser(['status' => 'suspended']);

        $this->assertNull($evOwner->stationOwnerAccessDeniedMessage());

        // A "suspended" ev_owner is still not gated by station-owner rules -
        // status is simply never inspected for this role.
        $this->actingAs($evOwner)->getJson('/api/auth/me')->assertStatus(200);
    }
}
