<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTestData;
use Tests\TestCase;

class ReviewTest extends TestCase
{
    use CreatesTestData;
    use RefreshDatabase;

    public function test_anyone_can_list_reviews_for_a_station(): void
    {
        $station = $this->makeStation();
        $reviewer = $this->makeUser();
        $station->reviews()->create(['user_id' => $reviewer->id, 'rating' => 4, 'comment' => 'Good']);

        $response = $this->getJson("/api/stations/{$station->id}/reviews");

        $response->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_authenticated_user_can_leave_a_review(): void
    {
        $user = $this->makeUser();
        $station = $this->makeStation();

        $response = $this->actingAs($user)->postJson("/api/stations/{$station->id}/reviews", [
            'rating' => 5,
            'comment' => 'Excellent station',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('rating', 5)
            ->assertJsonPath('user.id', $user->id);

        $this->assertDatabaseHas('reviews', ['user_id' => $user->id, 'station_id' => $station->id, 'rating' => 5]);
    }

    public function test_unauthenticated_user_cannot_leave_a_review(): void
    {
        $station = $this->makeStation();

        $this->postJson("/api/stations/{$station->id}/reviews", ['rating' => 5])
            ->assertStatus(401);
    }

    public function test_rating_must_be_between_one_and_five(): void
    {
        $user = $this->makeUser();
        $station = $this->makeStation();

        $this->actingAs($user)->postJson("/api/stations/{$station->id}/reviews", ['rating' => 6])
            ->assertStatus(422);

        $this->actingAs($user)->postJson("/api/stations/{$station->id}/reviews", ['rating' => 0])
            ->assertStatus(422);
    }
}
