<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
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
            'pending_station_verifications', 'recent_registrations', 'recent_admin_logins',
        ]);
        $this->assertSame(1, $response->json('pending_station_verifications'));
    }

    /**
     * Reversed from the original design: a portal can have more than one
     * admin, and a newly-registered admin account is exactly the kind of
     * event Recent Registrations exists to surface - so, unlike
     * active_today/users()/stations() (still admin-excluded, unrelated to
     * this widget), admin accounts now DO appear here.
     */
    public function test_admin_account_appears_in_recent_registrations(): void
    {
        $admin = $this->makeAdmin();
        $newestAdmin = $this->makeAdmin(['name' => 'Newest Admin']);
        $this->makeUser(['name' => 'Some Ev Owner']);

        $response = $this->actingAs($admin)->getJson('/api/admin/dashboard');

        $names = collect($response->json('recent_registrations'))->pluck('name');
        $this->assertTrue($names->contains('Newest Admin'));
    }

    public function test_recent_admin_logins_are_returned_newest_first_with_the_logging_in_admins_name(): void
    {
        $admin = $this->makeAdmin(['name' => 'First Admin']);
        $secondAdmin = $this->makeAdmin(['name' => 'Second Admin']);

        \App\Models\AdminLoginLog::create(['user_id' => $admin->id, 'ip_address' => '10.0.0.1', 'logged_in_at' => now()->subMinutes(5)]);
        \App\Models\AdminLoginLog::create(['user_id' => $secondAdmin->id, 'ip_address' => '10.0.0.2', 'logged_in_at' => now()]);

        $response = $this->actingAs($admin)->getJson('/api/admin/dashboard');

        $logins = $response->json('recent_admin_logins');
        $this->assertCount(2, $logins);
        $this->assertSame('Second Admin', $logins[0]['user']['name']);
        $this->assertSame('First Admin', $logins[1]['user']['name']);
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

        // Admin accounts are always excluded - only the ev_owner and
        // station_owner created above count, not the acting admin itself.
        $this->actingAs($admin)->getJson('/api/admin/users')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');

        $this->actingAs($admin)->getJson('/api/admin/users?role=station_owner')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_admin_role_is_always_excluded_even_when_explicitly_requested(): void
    {
        $admin = $this->makeAdmin();
        $this->makeAdmin();

        $this->actingAs($admin)->getJson('/api/admin/users?role=admin')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_admin_can_filter_users_by_status(): void
    {
        $admin = $this->makeAdmin();
        $this->makeStationOwner(['status' => 'pending']);
        $this->makeStationOwner(['status' => 'active']);

        $response = $this->actingAs($admin)->getJson('/api/admin/users?status=pending');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame('pending', $response->json('data.0.status'));
    }

    public function test_admin_can_filter_users_by_a_comma_separated_status_list(): void
    {
        $admin = $this->makeAdmin();
        $this->makeStationOwner(['status' => 'active']);
        $this->makeStationOwner(['status' => 'suspended']);
        $this->makeStationOwner(['status' => 'pending']);

        $response = $this->actingAs($admin)->getJson('/api/admin/users?role=station_owner&status=active,suspended');

        $response->assertStatus(200)->assertJsonCount(2, 'data');
    }

    public function test_admin_can_search_users_by_name(): void
    {
        $admin = $this->makeAdmin();
        $this->makeUser(['name' => 'Jane Searchable']);
        $this->makeUser(['name' => 'Someone Else']);

        $response = $this->actingAs($admin)->getJson('/api/admin/users?name=Searchable');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame('Jane Searchable', $response->json('data.0.name'));
    }

    public function test_admin_can_search_users_by_email(): void
    {
        $admin = $this->makeAdmin();
        $this->makeUser(['name' => 'No Name Match', 'email' => 'findme@example.com']);
        $this->makeUser(['name' => 'Also No Match', 'email' => 'other@example.com']);

        $response = $this->actingAs($admin)->getJson('/api/admin/users?name=findme');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame('findme@example.com', $response->json('data.0.email'));
    }

    /**
     * Regression guard for the where()-closure scoping in applyNameSearch():
     * a bare orWhere() here would OR against the role!='admin' exclusion
     * instead of AND-ing with it, silently leaking admin accounts back
     * into a search result.
     */
    public function test_admin_role_is_still_excluded_when_searching_by_name(): void
    {
        $admin = $this->makeAdmin();
        $this->makeAdmin(['name' => 'Findable Admin']);

        $response = $this->actingAs($admin)->getJson('/api/admin/users?name=Findable');

        $response->assertStatus(200)->assertJsonCount(0, 'data');
    }

    public function test_user_name_search_combines_with_role_and_status_filters(): void
    {
        $admin = $this->makeAdmin();
        $this->makeStationOwner(['name' => 'Match Pending', 'status' => 'pending']);
        $this->makeStationOwner(['name' => 'Match Active', 'status' => 'active']);
        $this->makeUser(['name' => 'Match Ev Owner']);

        $response = $this->actingAs($admin)->getJson('/api/admin/users?role=station_owner&status=pending&name=Match');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame('Match Pending', $response->json('data.0.name'));
    }

    public function test_non_admin_cannot_list_users(): void
    {
        $evOwner = $this->makeUser();

        $this->actingAs($evOwner)->getJson('/api/admin/users')->assertStatus(403);
    }

    public function test_admin_can_accept_a_pending_station_owner(): void
    {
        $admin = $this->makeAdmin();
        $owner = $this->makeStationOwner(['status' => 'pending']);

        $this->actingAs($admin)->putJson("/api/admin/users/{$owner->id}/status", ['status' => 'active'])
            ->assertStatus(200)
            ->assertJsonPath('status', 'active');
    }

    public function test_admin_can_reject_a_pending_station_owner(): void
    {
        $admin = $this->makeAdmin();
        $owner = $this->makeStationOwner(['status' => 'pending']);

        $this->actingAs($admin)->putJson("/api/admin/users/{$owner->id}/status", ['status' => 'rejected'])
            ->assertStatus(200)
            ->assertJsonPath('status', 'rejected');
    }

    public function test_admin_can_suspend_an_accepted_station_owner(): void
    {
        $admin = $this->makeAdmin();
        $owner = $this->makeStationOwner(['status' => 'active']);

        $this->actingAs($admin)->putJson("/api/admin/users/{$owner->id}/status", ['status' => 'suspended'])
            ->assertStatus(200)
            ->assertJsonPath('status', 'suspended');
    }

    public function test_changing_a_station_owners_status_notifies_them(): void
    {
        $admin = $this->makeAdmin();
        $owner = $this->makeStationOwner(['status' => 'pending']);

        $this->actingAs($admin)->putJson("/api/admin/users/{$owner->id}/status", ['status' => 'active'])
            ->assertStatus(200);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $owner->id,
            'type' => 'Account',
            'message' => 'Your station owner account status changed to active.',
        ]);
    }

    public function test_admin_can_reactivate_a_suspended_station_owner(): void
    {
        $admin = $this->makeAdmin();
        $owner = $this->makeStationOwner(['status' => 'suspended']);

        $this->actingAs($admin)->putJson("/api/admin/users/{$owner->id}/status", ['status' => 'active'])
            ->assertStatus(200)
            ->assertJsonPath('status', 'active');
    }

    /**
     * Full illegal-transition matrix for a station owner - covers every
     * edge the new approval workflow requires: an accepted owner can never
     * revert to pending, suspend can never apply before acceptance, and
     * rejected is a permanent dead end. Each case asserts both the 422 and
     * that the database value genuinely didn't move, not just that the
     * request failed.
     */
    public static function illegalUserTransitionProvider(): array
    {
        return [
            'pending cannot go straight to suspended' => ['pending', 'suspended'],
            'active can never revert to pending' => ['active', 'pending'],
            'active cannot be rejected directly' => ['active', 'rejected'],
            'suspended cannot revert to pending' => ['suspended', 'pending'],
            'suspended cannot be rejected' => ['suspended', 'rejected'],
            'rejected is terminal - cannot become active' => ['rejected', 'active'],
            'rejected is terminal - cannot become pending' => ['rejected', 'pending'],
            'rejected is terminal - cannot become suspended' => ['rejected', 'suspended'],
        ];
    }

    #[DataProvider('illegalUserTransitionProvider')]
    public function test_illegal_user_status_transitions_are_rejected(string $from, string $to): void
    {
        $admin = $this->makeAdmin();
        $owner = $this->makeStationOwner(['status' => $from]);

        $response = $this->actingAs($admin)->putJson("/api/admin/users/{$owner->id}/status", ['status' => $to]);

        $response->assertStatus(422);
        $this->assertSame($from, $owner->fresh()->status);
    }

    /**
     * EV owners have no approval workflow at all (see CLAUDE.md) - this
     * endpoint exists solely to manage station owner status, so any
     * attempt against an ev_owner target is rejected outright regardless
     * of the requested status.
     */
    public function test_ev_owner_target_is_rejected_from_the_user_status_endpoint(): void
    {
        $admin = $this->makeAdmin();
        $evOwner = $this->makeUser(['status' => 'active']);

        $response = $this->actingAs($admin)->putJson("/api/admin/users/{$evOwner->id}/status", ['status' => 'suspended']);

        $response->assertStatus(422);
        $this->assertSame('active', $evOwner->fresh()->status);
    }

    public function test_non_admin_cannot_update_a_users_status(): void
    {
        $stationOwner = $this->makeStationOwner();
        $user = $this->makeUser(['status' => 'active']);

        $this->actingAs($stationOwner)->putJson("/api/admin/users/{$user->id}/status", ['status' => 'suspended'])
            ->assertStatus(403);
    }

    /**
     * No recovery path exists in this project for a self-locked-out or
     * mutually-locked-out admin (no email reactivation, no superuser
     * override - only direct DB/tinker access), so this endpoint refuses
     * to touch any admin account's status at all, self or otherwise.
     */
    public function test_admin_cannot_change_their_own_status(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->putJson("/api/admin/users/{$admin->id}/status", ['status' => 'suspended']);

        $response->assertStatus(422);
        $this->assertSame('active', $admin->fresh()->status);
    }

    public function test_admin_cannot_change_another_admins_status(): void
    {
        $admin = $this->makeAdmin();
        $otherAdmin = $this->makeAdmin();

        $response = $this->actingAs($admin)->putJson("/api/admin/users/{$otherAdmin->id}/status", ['status' => 'suspended']);

        $response->assertStatus(422);
        $this->assertSame('active', $otherAdmin->fresh()->status);
    }

    public function test_admin_can_list_stations_filtered_by_status(): void
    {
        $admin = $this->makeAdmin();
        $this->makeStation(null, ['verification_status' => 'pending']);
        $this->makeStation(null, ['verification_status' => 'verified']);

        $this->actingAs($admin)->getJson('/api/admin/stations?status=pending')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_admin_can_list_stations_filtered_by_a_comma_separated_status_list(): void
    {
        $admin = $this->makeAdmin();
        $this->makeStation(null, ['verification_status' => 'verified']);
        $this->makeStation(null, ['verification_status' => 'suspended']);
        $this->makeStation(null, ['verification_status' => 'pending']);

        $response = $this->actingAs($admin)->getJson('/api/admin/stations?status=verified,suspended');

        $response->assertStatus(200)->assertJsonCount(2, 'data');
    }

    public function test_admin_can_search_stations_by_name(): void
    {
        $admin = $this->makeAdmin();
        $this->makeStation(null, ['name' => 'Findable Station']);
        $this->makeStation(null, ['name' => 'Other Station']);

        $response = $this->actingAs($admin)->getJson('/api/admin/stations?name=Findable');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame('Findable Station', $response->json('data.0.name'));
    }

    public function test_station_name_search_combines_with_status_filter(): void
    {
        $admin = $this->makeAdmin();
        $this->makeStation(null, ['name' => 'Match Pending', 'verification_status' => 'pending']);
        $this->makeStation(null, ['name' => 'Match Verified', 'verification_status' => 'verified']);

        $response = $this->actingAs($admin)->getJson('/api/admin/stations?status=pending&name=Match');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame('Match Pending', $response->json('data.0.name'));
    }

    public function test_admin_stations_list_is_paginated(): void
    {
        $admin = $this->makeAdmin();
        $this->makeStation(null, ['verification_status' => 'pending']);

        $response = $this->actingAs($admin)->getJson('/api/admin/stations');

        $response->assertStatus(200)->assertJsonStructure(['data', 'total', 'last_page', 'prev_page_url', 'next_page_url']);
    }

    public function test_non_admin_cannot_list_stations(): void
    {
        $evOwner = $this->makeUser();

        $this->actingAs($evOwner)->getJson('/api/admin/stations')->assertStatus(403);
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

    public function test_admin_can_suspend_a_verified_station(): void
    {
        $admin = $this->makeAdmin();
        $station = $this->makeStation(null, ['verification_status' => 'verified']);

        $this->actingAs($admin)->putJson("/api/admin/stations/{$station->id}/verify", [
            'verification_status' => 'suspended',
        ])->assertStatus(200)->assertJsonPath('verification_status', 'suspended');
    }

    public function test_verifying_a_station_notifies_its_owner(): void
    {
        $admin = $this->makeAdmin();
        $owner = $this->makeStationOwner();
        $station = $this->makeStation($owner, ['verification_status' => 'pending']);

        $this->actingAs($admin)->putJson("/api/admin/stations/{$station->id}/verify", [
            'verification_status' => 'verified',
        ])->assertStatus(200);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $owner->id,
            'type' => 'Station',
            'message' => "Your station \"{$station->name}\" status changed to verified.",
        ]);
    }

    public function test_verifying_an_ownerless_station_does_not_error_or_notify_anyone(): void
    {
        $admin = $this->makeAdmin();
        $station = $this->makeStation(null, ['verification_status' => 'pending']);

        $this->actingAs($admin)->putJson("/api/admin/stations/{$station->id}/verify", [
            'verification_status' => 'verified',
        ])->assertStatus(200);

        $this->assertDatabaseCount('user_notifications', 0);
    }

    public function test_admin_can_reactivate_a_suspended_station(): void
    {
        $admin = $this->makeAdmin();
        $station = $this->makeStation(null, ['verification_status' => 'suspended']);

        $this->actingAs($admin)->putJson("/api/admin/stations/{$station->id}/verify", [
            'verification_status' => 'verified',
        ])->assertStatus(200)->assertJsonPath('verification_status', 'verified');
    }

    /**
     * Same illegal-transition matrix as users, mirrored for stations: a
     * station can only be suspended once verified (never straight from
     * pending), can only return to verified (never to pending), and
     * rejected is a permanent dead end.
     */
    public static function illegalStationTransitionProvider(): array
    {
        return [
            'pending cannot go straight to suspended' => ['pending', 'suspended'],
            'verified cannot revert to pending' => ['verified', 'pending'],
            'verified cannot be rejected directly' => ['verified', 'rejected'],
            'suspended cannot revert to pending' => ['suspended', 'pending'],
            'suspended cannot be rejected' => ['suspended', 'rejected'],
            'rejected is terminal - cannot become verified' => ['rejected', 'verified'],
            'rejected is terminal - cannot become pending' => ['rejected', 'pending'],
            'rejected is terminal - cannot become suspended' => ['rejected', 'suspended'],
        ];
    }

    #[DataProvider('illegalStationTransitionProvider')]
    public function test_illegal_station_status_transitions_are_rejected(string $from, string $to): void
    {
        $admin = $this->makeAdmin();
        $station = $this->makeStation(null, ['verification_status' => $from]);

        $response = $this->actingAs($admin)->putJson("/api/admin/stations/{$station->id}/verify", [
            'verification_status' => $to,
        ]);

        $response->assertStatus(422);
        $this->assertSame($from, $station->fresh()->verification_status);
    }

    public function test_non_admin_cannot_verify_a_station(): void
    {
        $stationOwner = $this->makeStationOwner();
        $station = $this->makeStation(null, ['verification_status' => 'pending']);

        $this->actingAs($stationOwner)->putJson("/api/admin/stations/{$station->id}/verify", [
            'verification_status' => 'verified',
        ])->assertStatus(403);
    }

    public function test_verified_station_no_longer_appears_in_the_pending_list(): void
    {
        $admin = $this->makeAdmin();
        $station = $this->makeStation(null, ['verification_status' => 'pending']);

        $this->actingAs($admin)->putJson("/api/admin/stations/{$station->id}/verify", [
            'verification_status' => 'verified',
        ])->assertStatus(200);

        $this->actingAs($admin)->getJson('/api/admin/stations?status=pending')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    /**
     * Station verification and user approval are deliberately independent
     * gates (see the two-gate design under Product decisions) - verifying
     * or rejecting a station must never implicitly change anything on the
     * owning user, the same way updateUserStatus() never touches a
     * station. Asserted against the owner's real, fresh-from-the-database
     * status, not just that the request succeeded.
     */
    public function test_verifying_a_station_does_not_change_the_owners_user_status(): void
    {
        $admin = $this->makeAdmin();
        $owner = $this->makeStationOwner(['status' => 'pending']);
        $station = $this->makeStation($owner, ['verification_status' => 'pending']);

        $this->actingAs($admin)->putJson("/api/admin/stations/{$station->id}/verify", [
            'verification_status' => 'verified',
        ])->assertStatus(200);

        $this->assertSame('pending', $owner->fresh()->status);
    }

    public function test_rejecting_a_station_does_not_change_the_owners_user_status(): void
    {
        $admin = $this->makeAdmin();
        $owner = $this->makeStationOwner(['status' => 'active']);
        $station = $this->makeStation($owner, ['verification_status' => 'pending']);

        $this->actingAs($admin)->putJson("/api/admin/stations/{$station->id}/verify", [
            'verification_status' => 'rejected',
        ])->assertStatus(200);

        $this->assertSame('active', $owner->fresh()->status);
    }

    public function test_suspending_a_station_does_not_change_the_owners_user_status(): void
    {
        $admin = $this->makeAdmin();
        $owner = $this->makeStationOwner(['status' => 'active']);
        $station = $this->makeStation($owner, ['verification_status' => 'verified']);

        $this->actingAs($admin)->putJson("/api/admin/stations/{$station->id}/verify", [
            'verification_status' => 'suspended',
        ])->assertStatus(200);

        $this->assertSame('active', $owner->fresh()->status);
    }

    /**
     * Regression test for the bundled bug fix in stats(): active_today
     * used to count admin accounts too, unlike users()/recent_registrations,
     * which both already excluded them. An admin whose updated_at is today
     * must not inflate this number.
     */
    public function test_dashboard_stats_active_today_excludes_admin_accounts(): void
    {
        $admin = $this->makeAdmin();
        $otherAdmin = $this->makeAdmin(); // updated_at is "today" by virtue of just being created
        $this->makeUser(); // also updated today

        $response = $this->actingAs($admin)->getJson('/api/admin/dashboard');

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('active_today'));
    }

    public function test_admin_active_today_lists_users_active_today(): void
    {
        $admin = $this->makeAdmin();
        $activeToday = $this->makeUser();
        $notActiveToday = $this->makeUser();
        // Two gotchas stacked here: save() always overwrites updated_at to
        // now() unless timestamps are disabled first, AND updated_at isn't
        // in User::$fillable, so a plain update() silently discards it
        // (no exception - mass-assignment protection just no-ops) -
        // forceFill() is required to actually set it.
        $notActiveToday->timestamps = false;
        $notActiveToday->forceFill(['updated_at' => now()->subDays(3)])->save();

        $response = $this->actingAs($admin)->getJson('/api/admin/active-today');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame($activeToday->id, $response->json('data.0.id'));
    }

    public function test_admin_active_today_excludes_admin_accounts(): void
    {
        $admin = $this->makeAdmin();
        $this->makeAdmin(); // updated today, but must never appear

        $response = $this->actingAs($admin)->getJson('/api/admin/active-today');

        $response->assertStatus(200)->assertJsonCount(0, 'data');
    }

    public function test_admin_active_today_filters_by_role(): void
    {
        $admin = $this->makeAdmin();
        $this->makeUser();
        $this->makeStationOwner();

        $response = $this->actingAs($admin)->getJson('/api/admin/active-today?role=station_owner');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame('station_owner', $response->json('data.0.role'));
    }

    public function test_admin_active_today_search_by_name(): void
    {
        $admin = $this->makeAdmin();
        $this->makeUser(['name' => 'Findable Today']);
        $this->makeUser(['name' => 'Not A Match']);

        $response = $this->actingAs($admin)->getJson('/api/admin/active-today?name=Findable');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame('Findable Today', $response->json('data.0.name'));
    }

    public function test_admin_active_today_list_is_paginated(): void
    {
        $admin = $this->makeAdmin();
        $this->makeUser();

        $response = $this->actingAs($admin)->getJson('/api/admin/active-today');

        $response->assertStatus(200)->assertJsonStructure(['data', 'total', 'last_page', 'prev_page_url', 'next_page_url']);
    }

    public function test_non_admin_cannot_view_active_today(): void
    {
        $evOwner = $this->makeUser();

        $this->actingAs($evOwner)->getJson('/api/admin/active-today')->assertStatus(403);
    }
}
