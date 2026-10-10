<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/api/settings/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patchJson('/api/settings/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response->assertOk()->assertJsonPath('message', __('Profile updated.'));

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patchJson('/api/settings/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response->assertOk()->assertJsonPath('message', __('Profile updated.'));

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_upload_and_remove_a_profile_avatar(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/api/settings/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('profile.png', 256, 256),
        ], ['Accept' => 'application/json']);

        $response->assertOk()->assertJsonPath('message', 'Profile photo updated.')
            ->assertJsonPath('data.user.avatar_url', fn ($url) => str_starts_with($url, '/api/settings/profile/avatar?v='));
        $path = $user->refresh()->avatar_path;
        $this->assertNotNull($path);
        Storage::disk('local')->assertExists($path);
        $this->getJson('/api/me')->assertOk()
            ->assertJsonPath('data.user.avatar_url', fn ($url) => str_contains($url, rawurlencode($path)));

        $this->actingAs($user)->get('/api/settings/profile/avatar')->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->actingAs($user)->deleteJson('/api/settings/profile/avatar')->assertOk()
            ->assertJsonPath('data.user.avatar_url', null);
        $this->assertNull($user->refresh()->avatar_path);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_profile_avatar_upload_rejects_non_images_and_oversized_files(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $this->actingAs($user)->post('/api/settings/profile/avatar', [
            'avatar' => UploadedFile::fake()->create('notes.txt', 20, 'text/plain'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('avatar');

        $this->post('/api/settings/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('large.png')->size(6000),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('avatar');
    }

    public function test_user_can_delete_their_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->deleteJson('/api/settings/profile', [
                'password' => 'password',
            ]);

        $response->assertOk()->assertJsonPath('message', 'Account deleted.');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->deleteJson('/api/settings/profile', [
                'password' => 'wrong-password',
            ]);

        $response->assertJsonValidationErrors('password');

        $this->assertNotNull($user->fresh());
    }
}
