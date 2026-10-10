<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TicketStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class TicketNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_read_or_update_notifications(): void
    {
        $this->getJson('/api/notifications')->assertUnauthorized();
        $this->patchJson('/api/notifications/1/read')->assertUnauthorized();
    }

    public function test_inbox_is_scoped_to_authenticated_user(): void
    {
        $user = User::factory()->create();
        $this->mock(TicketStore::class, function (MockInterface $mock) use ($user) {
            $mock->shouldReceive('ready')->andReturn(true);
            $mock->shouldReceive('call')->with('TicketNotifications', [$user->id, 1])->once()->andReturn([]);
        });
        $this->actingAs($user)->getJson('/api/notifications?actor=999')
            ->assertOk()->assertJsonPath('data.notifications', []);
    }

    public function test_read_uses_session_actor(): void
    {
        $user = User::factory()->create();
        $this->mock(TicketStore::class, fn (MockInterface $mock) => $mock->shouldReceive('call')
            ->with('TicketNotificationRead', [$user->id, 5])->once()->andReturn([]));
        $this->actingAs($user)->patchJson('/api/notifications/5/read', ['actor' => 999])->assertOk()->assertJsonPath('message', 'Saved.');
    }

    public function test_invalid_page_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/notifications?page=-1')->assertJsonValidationErrors('page');
    }
}
