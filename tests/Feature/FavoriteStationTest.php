<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTestData;
use Tests\TestCase;

class FavoriteStationTest extends TestCase
{
    use CreatesTestData;
    use RefreshDatabase;

    public function test_user_can_favorite_a_station(): void
    {
        $user = $this->makeUser();
        $station = $this->makeStation();

        $response = $this->actingAs($user)->postJson('/api/favorites', ['station_id' => $station->id]);

        $response->assertStatus(201)->assertJsonPath('station_id', $station->id);
        $this->assertDatabaseHas('favorite_stations', ['user_id' => $user->id, 'station_id' => $station->id]);
    }

    public function test_favoriting_the_same_station_twice_does_not_duplicate(): void
    {
        $user = $this->makeUser();
        $station = $this->makeStation();

        $this->actingAs($user)->postJson('/api/favorites', ['station_id' => $station->id])->assertStatus(201);
        $this->actingAs($user)->postJson('/api/favorites', ['station_id' => $station->id])->assertStatus(201);

        $this->assertSame(1, \App\Models\FavoriteStation::where('user_id', $user->id)
            ->where('station_id', $station->id)->count());
    }

    public function test_index_only_returns_the_authenticated_users_favorites(): void
    {
        $userA = $this->makeUser();
        $userB = $this->makeUser();
        $stationA = $this->makeStation();
        $stationB = $this->makeStation();

        $userA->favoriteStations()->create(['station_id' => $stationA->id]);
        $userB->favoriteStations()->create(['station_id' => $stationB->id]);

        $response = $this->actingAs($userA)->getJson('/api/favorites');

        $response->assertStatus(200)->assertJsonCount(1)->assertJsonPath('0.station_id', $stationA->id);
    }

    public function test_user_can_remove_own_favorite(): void
    {
        $user = $this->makeUser();
        $station = $this->makeStation();
        $user->favoriteStations()->create(['station_id' => $station->id]);

        $this->actingAs($user)->deleteJson("/api/favorites/{$station->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('favorite_stations', ['user_id' => $user->id, 'station_id' => $station->id]);
    }

    public function test_user_cannot_remove_another_users_favorite(): void
    {
        $owner = $this->makeUser();
        $intruder = $this->makeUser();
        $station = $this->makeStation();
        $owner->favoriteStations()->create(['station_id' => $station->id]);

        $this->actingAs($intruder)->deleteJson("/api/favorites/{$station->id}")
            ->assertStatus(200);

        // Scoped delete is a silent no-op for records the intruder doesn't own.
        $this->assertDatabaseHas('favorite_stations', ['user_id' => $owner->id, 'station_id' => $station->id]);
    }
}
