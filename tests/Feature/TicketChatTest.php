<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TicketStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\TestCase;

class TicketChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_requires_authentication_and_view_permission(): void
    {
        $this->getJson('/api/tickets/1/chat')->assertUnauthorized();
        DB::table('role_permissions')->where('role', 'requester')->where('permission', 'tickets.view')->update(['allowed' => false]);
        $this->actingAs(User::factory()->create())->getJson('/api/tickets/1/chat')->assertForbidden();
        $this->postJson('/api/tickets/1/chat/presence', ['typing' => true, 'internal' => false, 'seen' => 0])->assertForbidden();
    }

    public function test_chat_orders_messages_and_uses_authenticated_actor(): void
    {
        $user = User::factory()->create();
        $this->mock(TicketStore::class, fn (MockInterface $mock) => $mock->shouldReceive('call')->once()
            ->with('TicketChatRead', [$user->id, 8, 4])->andReturn([(object) [
                'messages_json' => '[{"id":6,"body":"second"},{"id":5,"body":"first"}]',
                'typing_json' => '[]', 'unread' => 2,
            ]]));
        $this->actingAs($user)->getJson('/api/tickets/8/chat?after=4&actor=999')->assertOk()
            ->assertJsonPath('data.messages.0.id', 5)->assertJsonPath('data.unread', 2)->assertJsonPath('data.has_more', false);
    }

    public function test_presence_validates_inputs_and_uses_actor(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/tickets/8/chat/presence', ['typing' => 'bad'])->assertUnprocessable();
        $this->mock(TicketStore::class, fn (MockInterface $mock) => $mock->shouldReceive('call')->once()
            ->with('TicketChatPresence', [$user->id, 8, 0, 0, 12])->andReturn([]));
        $this->postJson('/api/tickets/8/chat/presence', ['typing' => false, 'internal' => false, 'seen' => 12, 'actor' => 999])->assertOk();
    }
}
