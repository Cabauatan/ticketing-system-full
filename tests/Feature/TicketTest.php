<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TicketStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class TicketTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_access_tickets(): void
    {
        $this->getJson('/api/tickets')->assertUnauthorized();
        $this->postJson('/api/tickets')->assertUnauthorized();
    }

    public function test_non_mysql_connection_displays_setup_state(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/tickets')
            ->assertOk()
            ->assertJsonPath('data.databaseReady', false)->assertJsonPath('data.tickets', []);
    }

    public function test_requester_cannot_manage_tickets_or_post_internal_notes(): void
    {
        $this->actingAs(User::factory()->create())->patchJson('/api/tickets/1', ['status' => 'closed'])->assertForbidden();
        $this->postJson('/api/tickets/1/comments', ['body' => 'Private', 'internal' => true])->assertForbidden();
    }

    public function test_ticket_creation_uses_authenticated_actor_and_bound_values(): void
    {
        $user = User::factory()->create();
        $this->mock(TicketStore::class, function (MockInterface $mock) use ($user) {
            $mock->shouldReceive('call')->once()->with('TicketCreate', [$user->id, 'Broken laptop', 'Will not start', 'hardware', 'low'])->andReturn([(object) ['id' => 42]]);
        });
        $this->actingAs($user)->postJson('/api/tickets', [
            'subject' => 'Broken laptop', 'description' => 'Will not start',
            'category' => 'hardware', 'priority' => 'high', 'requester_id' => 999,
        ])->assertCreated()->assertJsonPath('data.id', 42);
    }

    public function test_invalid_ticket_is_rejected_before_database_call(): void
    {
        $this->mock(TicketStore::class, fn (MockInterface $mock) => $mock->shouldNotReceive('call'));
        $this->actingAs(User::factory()->create())->postJson('/api/tickets', ['priority' => 'invalid'])
            ->assertJsonValidationErrors(['subject', 'description', 'category', 'priority']);
    }
}
