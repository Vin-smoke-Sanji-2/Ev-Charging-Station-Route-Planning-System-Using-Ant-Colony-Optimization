<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTestData;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use CreatesTestData;
    use RefreshDatabase;

    public function test_admin_can_view_dashboard_stats(): void
    {
        $admin = $this->makeAdmin();
        $this->makeStation(null, ['verification_status' => 'pending']);

        $response = $this->actingAs($admin)->getJson('/api/admin/dashboard');

        $response->assertStatus(200)->assertJsonStructure([
            'total_users', 'total_stations', 'total_trips', 'active_today',
            'pending_station_verifications', 'recent_registrations',
        ]);
        $this->assertSame(1, $response->json('pending_station_verifications'));
    }

    public function test_non_admin_cannot_view_dashboard_stats(): void
    {
        $evOwner = $this->makeUser();
        $stationOwner = $this->makeStationOwner();

        $this->actingAs($evOwner)->getJson('/api/admin/dashboard')->assertStatus(403);
        $this->actingAs($stationOwner)->getJson('/api/admin/dashboard')->assertStatus(403);
    }

    public function test_admin_can_list_users(): void
    {
        $admin = $this->makeAdmin();
        $this->makeUser();
        $this->makeStationOwner();

        $this->actingAs($admin)->getJson('/api/admin/users')
            ->assertStatus(200)
            ->assertJsonCount(3, 'data'); // admin + the two created above

        $this->actingAs($admin)->getJson('/api/admin/users?role=station_owner')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_non_admin_cannot_list_users(): void
    {
        $evOwner = $this->makeUser();

        $this->actingAs($evOwner)->getJson('/api/admin/users')->assertStatus(403);
    }

    public function test_admin_can_update_a_users_status(): void
    {
        $admin = $this->makeAdmin();
        $user = $this->makeUser(['status' => 'active']);

        $this->actingAs($admin)->putJson("/api/admin/users/{$user->id}/status", ['status' => 'suspended'])
            ->assertStatus(200)
            ->assertJsonPath('status', 'suspended');
    }

    public function test_non_admin_cannot_update_a_users_status(): void
    {
        $stationOwner = $this->makeStationOwner();
        $user = $this->makeUser(['status' => 'active']);

        $this->actingAs($stationOwner)->putJson("/api/admin/users/{$user->id}/status", ['status' => 'suspended'])
            ->assertStatus(403);
    }

    public function test_admin_can_list_pending_stations(): void
    {
        $admin = $this->makeAdmin();
        $this->makeStation(null, ['verification_status' => 'pending']);
        $this->makeStation(null, ['verification_status' => 'verified']);

        $this->actingAs($admin)->getJson('/api/admin/stations/pending')
            ->assertStatus(200)
            ->assertJsonCount(1);
    }

    public function test_non_admin_cannot_list_pending_stations(): void
    {
        $evOwner = $this->makeUser();

        $this->actingAs($evOwner)->getJson('/api/admin/stations/pending')->assertStatus(403);
    }

    public function test_admin_can_verify_a_station(): void
    {
        $admin = $this->makeAdmin();
        $station = $this->makeStation(null, ['verification_status' => 'pending']);

        $this->actingAs($admin)->putJson("/api/admin/stations/{$station->id}/verify", [
            'verification_status' => 'verified',
        ])->assertStatus(200)->assertJsonPath('verification_status', 'verified');
    }

    public function test_admin_can_reject_a_station(): void
    {
        $admin = $this->makeAdmin();
        $station = $this->makeStation(null, ['verification_status' => 'pending']);

        $this->actingAs($admin)->putJson("/api/admin/stations/{$station->id}/verify", [
            'verification_status' => 'rejected',
        ])->assertStatus(200)->assertJsonPath('verification_status', 'rejected');
    }

    public function test_non_admin_cannot_verify_a_station(): void
    {
        $stationOwner = $this->makeStationOwner();
        $station = $this->makeStation(null, ['verification_status' => 'pending']);

        $this->actingAs($stationOwner)->putJson("/api/admin/stations/{$station->id}/verify", [
            'verification_status' => 'verified',
        ])->assertStatus(403);
    }
}
