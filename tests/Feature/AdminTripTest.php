<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTestData;
use Tests\TestCase;

class AdminTripTest extends TestCase
{
    use CreatesTestData;
    use RefreshDatabase;

    public function test_admin_can_list_trips(): void
    {
        $admin = $this->makeAdmin();
        $user = $this->makeUser();
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);
        $this->makeTripRequest($user, $origin, $destination);

        $response = $this->actingAs($admin)->getJson('/api/admin/trips');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_trips_list_includes_rider_vehicle_route_and_status_details(): void
    {
        $admin = $this->makeAdmin();
        $user = $this->makeUser(['name' => 'Trip Rider']);
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);
        $trip = $this->makeTripRequest($user, $origin, $destination);
        $this->makeTripRoute($trip, ['status' => 'planned', 'total_distance_km' => 100, 'estimated_cost' => 5000]);

        $response = $this->actingAs($admin)->getJson('/api/admin/trips');

        $response->assertStatus(200);
        $this->assertSame('Trip Rider', $response->json('data.0.user.name'));
        $this->assertSame('Yangon', $response->json('data.0.origin_node.name'));
        $this->assertSame('Mandalay', $response->json('data.0.destination_node.name'));
        $this->assertSame('planned', $response->json('data.0.latest_route.status'));
    }

    public function test_admin_can_filter_trips_by_latest_route_status(): void
    {
        $admin = $this->makeAdmin();
        $user = $this->makeUser();
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);

        $planned = $this->makeTripRequest($user, $origin, $destination);
        $this->makeTripRoute($planned, ['status' => 'planned']);

        $completed = $this->makeTripRequest($user, $origin, $destination);
        $this->makeTripRoute($completed, ['status' => 'completed']);

        $response = $this->actingAs($admin)->getJson('/api/admin/trips?status=completed');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame($completed->id, $response->json('data.0.id'));
    }

    public function test_trip_with_no_route_yet_is_excluded_by_a_status_filter_but_included_unfiltered(): void
    {
        $admin = $this->makeAdmin();
        $user = $this->makeUser();
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);
        $this->makeTripRequest($user, $origin, $destination); // no TripRoute at all

        $filtered = $this->actingAs($admin)->getJson('/api/admin/trips?status=planned');
        $filtered->assertStatus(200)->assertJsonCount(0, 'data');

        $unfiltered = $this->actingAs($admin)->getJson('/api/admin/trips');
        $unfiltered->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_admin_can_search_trips_by_rider_name(): void
    {
        $admin = $this->makeAdmin();
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);

        $findable = $this->makeUser(['name' => 'Findable Rider']);
        $this->makeTripRequest($findable, $origin, $destination);

        $other = $this->makeUser(['name' => 'Other Rider']);
        $this->makeTripRequest($other, $origin, $destination);

        $response = $this->actingAs($admin)->getJson('/api/admin/trips?name=Findable');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame('Findable Rider', $response->json('data.0.user.name'));
    }

    public function test_trips_list_is_paginated(): void
    {
        $admin = $this->makeAdmin();
        $user = $this->makeUser();
        $origin = $this->makeRoadNode(['name' => 'Yangon']);
        $destination = $this->makeRoadNode(['name' => 'Mandalay']);
        $this->makeTripRequest($user, $origin, $destination);

        $response = $this->actingAs($admin)->getJson('/api/admin/trips');

        $response->assertStatus(200)->assertJsonStructure(['data', 'total', 'last_page', 'prev_page_url', 'next_page_url']);
    }

    public function test_non_admin_cannot_list_trips(): void
    {
        $evOwner = $this->makeUser();

        $this->actingAs($evOwner)->getJson('/api/admin/trips')->assertStatus(403);
    }
}
