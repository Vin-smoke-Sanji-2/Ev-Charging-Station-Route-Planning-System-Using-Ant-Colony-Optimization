<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTestData;
use Tests\TestCase;

class RoleMiddlewareTest extends TestCase
{
    use CreatesTestData;
    use RefreshDatabase;

    public function test_ev_owner_is_forbidden_from_a_station_owner_only_route(): void
    {
        $evOwner = $this->makeUser(['role' => 'ev_owner']);

        $response = $this->actingAs($evOwner)->postJson('/api/stations', [
            'name' => 'Should Be Blocked',
            'latitude' => 16.8,
            'longitude' => 96.15,
        ]);

        $response->assertStatus(403)->assertJson(['message' => 'Forbidden: insufficient role']);
    }

    public function test_station_owner_is_forbidden_from_an_admin_only_route(): void
    {
        $stationOwner = $this->makeStationOwner();

        $response = $this->actingAs($stationOwner)->getJson('/api/admin/dashboard');

        $response->assertStatus(403)->assertJson(['message' => 'Forbidden: insufficient role']);
    }

    public function test_station_owner_and_admin_are_both_allowed_on_the_shared_role_route(): void
    {
        $stationOwner = $this->makeStationOwner();
        $admin = $this->makeAdmin();

        $this->actingAs($stationOwner)->postJson('/api/stations', [
            'name' => 'Owner Station',
            'latitude' => 16.8,
            'longitude' => 96.15,
        ])->assertStatus(201);

        $this->actingAs($admin)->postJson('/api/stations', [
            'name' => 'Admin Station',
            'latitude' => 16.8,
            'longitude' => 96.15,
        ])->assertStatus(201);
    }

    /**
     * Role-aware "/" redirect (routes/web.php) - an already-logged-in user
     * of any role is bounced straight to their own portal instead of the
     * marketing home page. Reuses User::landingPage(), the same mapping
     * EnsureUserBelongsToPortal redirects with, so these tests assert
     * against real routes/that method's own output rather than a second,
     * separately-hardcoded expectation.
     */
    public function test_guest_sees_the_home_page(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200)->assertViewIs('home');
    }

    public function test_ev_owner_is_redirected_from_home_to_dashboard(): void
    {
        $evOwner = $this->makeUser(['role' => 'ev_owner']);

        $this->actingAs($evOwner)->get('/')->assertRedirect('/dashboard');
    }

    public function test_active_station_owner_is_redirected_from_home_to_overview(): void
    {
        $stationOwner = $this->makeStationOwner();

        $this->actingAs($stationOwner)->get('/')->assertRedirect('/station-owner/overview');
    }

    /**
     * landingPage() is deliberately status-agnostic - a pending station
     * owner still belongs on the page that explains their status, not
     * back on the marketing page. This must not regress into a status
     * check being added to the "/" route.
     */
    public function test_pending_station_owner_is_also_redirected_from_home_to_overview(): void
    {
        $stationOwner = $this->makeStationOwner(['status' => 'pending']);

        $this->actingAs($stationOwner)->get('/')->assertRedirect('/station-owner/overview');
    }

    /**
     * Asserts against landingPage()'s own output rather than a hardcoded
     * path - this test was originally written before a real admin portal
     * existed (when landingPage() fell back to /dashboard for 'admin'),
     * deliberately dynamic so it would keep passing, unchanged, the moment
     * a real admin landing page got added - which is exactly what
     * happened once the Admin portal's Overview page landed.
     */
    public function test_admin_is_redirected_from_home_to_their_own_overview(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->get('/')->assertRedirect($admin->landingPage());
        $this->assertSame('/admin/overview', $admin->landingPage());
    }

    public function test_non_admin_is_redirected_from_admin_overview_to_their_own_landing_page(): void
    {
        $evOwner = $this->makeUser(['role' => 'ev_owner']);
        $stationOwner = $this->makeStationOwner();

        $this->actingAs($evOwner)->get('/admin/overview')->assertRedirect('/dashboard');
        $this->actingAs($stationOwner)->get('/admin/overview')->assertRedirect('/station-owner/overview');
    }

    public function test_admin_can_load_the_admin_overview_page(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin)->get('/admin/overview')
            ->assertStatus(200)
            ->assertViewIs('admin.overview');
    }

    /**
     * /profile and the coming-soon placeholder both pick their layout via
     * User::layoutFor() (see its doc comment) instead of a hand-rolled
     * per-page branch - this used to be a two-way isStationOwner() check
     * on both views, which silently fell through to the EV owner's layout
     * for admin once the Admin portal existed. Asserted via rendered
     * content (the admin sidebar's "Overview" nav item, which only
     * layouts.admin renders) rather than assertViewIs(), since /profile
     * always renders the same 'profile.index' view regardless of role -
     * the layout is a @extends inside that view, not a separate view name.
     */
    public function test_admin_hitting_profile_gets_the_admin_layout_not_the_ev_owner_layout(): void
    {
        $admin = $this->makeAdmin();

        $response = $this->actingAs($admin)->get('/profile');

        $response->assertStatus(200)->assertViewIs('profile.index');
        $response->assertSee(route('admin.overview'), false);
        $response->assertDontSee('Plan Trip');
    }

    public function test_station_owner_hitting_profile_still_gets_the_station_owner_layout(): void
    {
        $stationOwner = $this->makeStationOwner();

        $response = $this->actingAs($stationOwner)->get('/profile');

        $response->assertStatus(200)->assertViewIs('profile.index');
        $response->assertSee(route('station-owner.overview'), false);
    }
}
