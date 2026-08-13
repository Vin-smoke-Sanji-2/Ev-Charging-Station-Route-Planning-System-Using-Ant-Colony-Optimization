<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\CreatesTestData;
use Tests\TestCase;

class UserNotificationTest extends TestCase
{
    use CreatesTestData;
    use RefreshDatabase;

    public function test_index_only_returns_the_authenticated_users_notifications(): void
    {
        $userA = $this->makeUser();
        $userB = $this->makeUser();
        $userA->appNotifications()->create(['type' => 'info', 'message' => 'For A']);
        $userB->appNotifications()->create(['type' => 'info', 'message' => 'For B']);

        $response = $this->actingAs($userA)->getJson('/api/notifications');

        $response->assertStatus(200)->assertJsonCount(1, 'data')->assertJsonPath('data.0.message', 'For A');
    }

    public function test_user_can_mark_own_notification_read(): void
    {
        $user = $this->makeUser();
        $notification = $user->appNotifications()->create(['type' => 'info', 'message' => 'Hi', 'is_read' => false]);

        $this->actingAs($user)->putJson("/api/notifications/{$notification->id}/read")
            ->assertStatus(200)
            ->assertJsonPath('is_read', true);
    }

    public function test_user_cannot_mark_another_users_notification_read(): void
    {
        $owner = $this->makeUser();
        $intruder = $this->makeUser();
        $notification = $owner->appNotifications()->create(['type' => 'info', 'message' => 'Hi', 'is_read' => false]);

        $this->actingAs($intruder)->putJson("/api/notifications/{$notification->id}/read")
            ->assertStatus(403);

        $this->assertDatabaseHas('user_notifications', ['id' => $notification->id, 'is_read' => false]);
    }

    /**
     * The sidebar unread badge (notification-badge.js) only needs this
     * filtered count - ?is_read=0's paginator `total` is the whole reason
     * this filter exists.
     */
    public function test_index_can_filter_by_is_read(): void
    {
        $user = $this->makeUser();
        $user->appNotifications()->create(['type' => 'info', 'message' => 'Read one', 'is_read' => true]);
        $user->appNotifications()->create(['type' => 'info', 'message' => 'Unread one', 'is_read' => false]);
        $user->appNotifications()->create(['type' => 'info', 'message' => 'Unread two', 'is_read' => false]);

        $unread = $this->actingAs($user)->getJson('/api/notifications?is_read=0');
        $unread->assertStatus(200)->assertJsonPath('total', 2);

        $read = $this->actingAs($user)->getJson('/api/notifications?is_read=1');
        $read->assertStatus(200)->assertJsonPath('total', 1);
    }

    public function test_mark_all_read_only_affects_own_notifications(): void
    {
        $userA = $this->makeUser();
        $userB = $this->makeUser();
        $userA->appNotifications()->create(['type' => 'info', 'message' => 'A1', 'is_read' => false]);
        $userA->appNotifications()->create(['type' => 'info', 'message' => 'A2', 'is_read' => false]);
        $userBNotification = $userB->appNotifications()->create(['type' => 'info', 'message' => 'B1', 'is_read' => false]);

        $this->actingAs($userA)->putJson('/api/notifications/read-all')
            ->assertStatus(200)
            ->assertJson(['message' => 'All notifications marked as read']);

        $this->assertSame(0, $userA->appNotifications()->where('is_read', false)->count());
        $this->assertDatabaseHas('user_notifications', ['id' => $userBNotification->id, 'is_read' => false]);
    }
}
