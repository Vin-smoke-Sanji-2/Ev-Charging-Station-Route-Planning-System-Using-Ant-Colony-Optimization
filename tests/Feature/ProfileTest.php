<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\CreatesTestData;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use CreatesTestData;
    use RefreshDatabase;

    public function test_uploading_a_valid_avatar_succeeds(): void
    {
        Storage::fake('public');
        $user = $this->makeUser();

        $response = $this->actingAs($user)->postJson('/api/auth/avatar', [
            'avatar' => UploadedFile::fake()->create('avatar.jpg', 100),
        ]);

        $response->assertOk()->assertJsonPath('avatar_url', fn ($url) => ! empty($url));

        $user->refresh();
        Storage::disk('public')->assertExists($user->avatar_path);
    }

    public function test_uploading_a_non_image_file_is_rejected(): void
    {
        Storage::fake('public');
        $user = $this->makeUser();

        $response = $this->actingAs($user)->postJson('/api/auth/avatar', [
            'avatar' => UploadedFile::fake()->create('file.txt', 10),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('avatar');
        $this->assertNull($user->fresh()->avatar_path);
    }

    public function test_uploading_a_second_avatar_deletes_the_first_from_storage(): void
    {
        Storage::fake('public');
        $user = $this->makeUser();

        $this->actingAs($user)->postJson('/api/auth/avatar', [
            'avatar' => UploadedFile::fake()->create('first.jpg', 100),
        ])->assertOk();
        $firstPath = $user->fresh()->avatar_path;

        $response = $this->actingAs($user)->postJson('/api/auth/avatar', [
            'avatar' => UploadedFile::fake()->create('second.jpg', 100),
        ]);
        $response->assertOk();

        $secondPath = $user->fresh()->avatar_path;

        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);
        $this->assertNotNull($response->json('avatar_url'));
    }

    public function test_deleting_the_avatar_clears_it_and_removes_the_file(): void
    {
        Storage::fake('public');
        $user = $this->makeUser();

        $this->actingAs($user)->postJson('/api/auth/avatar', [
            'avatar' => UploadedFile::fake()->create('avatar.jpg', 100),
        ])->assertOk();
        $path = $user->fresh()->avatar_path;

        $response = $this->actingAs($user)->deleteJson('/api/auth/avatar');

        $response->assertOk()
            ->assertJsonPath('avatar_url', null);

        $this->assertNull($user->fresh()->avatar_path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_deleting_an_avatar_when_none_exists_is_a_safe_no_op(): void
    {
        Storage::fake('public');
        $user = $this->makeUser();

        $response = $this->actingAs($user)->deleteJson('/api/auth/avatar');

        $response->assertOk()->assertJsonPath('avatar_url', null);
    }

    public function test_avatar_upload_requires_authentication(): void
    {
        Storage::fake('public');

        $this->postJson('/api/auth/avatar', [
            'avatar' => UploadedFile::fake()->create('avatar.jpg', 100),
        ])->assertStatus(401);
    }

    public function test_avatar_delete_requires_authentication(): void
    {
        $this->deleteJson('/api/auth/avatar')->assertStatus(401);
    }
}
