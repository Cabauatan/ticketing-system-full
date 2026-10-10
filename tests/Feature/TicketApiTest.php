<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TicketStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class TicketApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_receive_json_without_accept_header(): void
    {
        $this->get('/api/tickets')->assertUnauthorized()->assertJsonStructure(['message']);
    }

    public function test_unverified_users_are_forbidden_without_redirect(): void
    {
        $this->actingAs(User::factory()->unverified()->create())->get('/api/tickets')
            ->assertForbidden()->assertJsonStructure(['message']);
    }

    public function test_current_user_only_exposes_public_account_fields(): void
    {
        $user = User::factory()->create()->refresh();
        $this->actingAs($user)->get('/api/user')->assertOk()
            ->assertExactJson(['data' => [
                'id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => 'requester',
                'roles' => ['requester'], 'avatar_url' => null,
            ]]);
    }

    public function test_create_uses_session_actor_and_returns_created_id(): void
    {
        $user = User::factory()->create();
        $this->mock(TicketStore::class, function (MockInterface $mock) use ($user) {
            $mock->shouldReceive('call')->once()->with('TicketCreate', [$user->id, 'Laptop', 'Broken', 'hardware', 'low'])->andReturn([(object) ['id' => 42]]);
        });
        $this->actingAs($user)->post('/api/tickets', ['subject' => 'Laptop', 'description' => 'Broken', 'category' => 'hardware', 'priority' => 'high', 'requester_id' => 999])
            ->assertCreated()->assertExactJson(['data' => ['id' => 42]])->assertHeaderMissing('Location');
    }

    public function test_validation_errors_are_json_without_accept_header(): void
    {
        $this->actingAs(User::factory()->create())->post('/api/tickets', [])
            ->assertUnprocessable()->assertJsonValidationErrors(['subject', 'description', 'category']);
    }

    public function test_requester_cannot_manage_or_post_internal_notes(): void
    {
        $this->actingAs(User::factory()->create())->patch('/api/tickets/1', [])->assertForbidden();
        $this->post('/api/tickets/1/comments', ['body' => 'Secret', 'internal' => true])->assertForbidden();
        $this->get('/api/admin')->assertForbidden();
        $this->patch('/api/admin/users/2/role', ['role' => 'admin'])->assertForbidden();
    }

    public function test_show_returns_decoded_data_and_uses_actor_for_every_lookup(): void
    {
        $user = User::factory()->create();
        $this->mock(TicketStore::class, function (MockInterface $mock) use ($user) {
            $mock->shouldReceive('call')->once()->with('TicketShow', [$user->id, 42])->andReturn([(object) ['id' => 42, 'priority' => 'low', 'assignees_json' => '[]', 'comments_json' => '[]', 'events_json' => '[]']]);
            $mock->shouldReceive('call')->once()->with('TicketAttachmentList', [$user->id, 42])->andReturn([]);
        });
        $this->actingAs($user)->get('/api/tickets/42')->assertOk()->assertJsonPath('data.ticket.id', 42)
            ->assertJsonPath('data.ticket.comments', [])->assertJsonPath('data.canManage', false)
            ->assertJsonPath('data.canAssign', false)->assertJsonPath('data.agents', [])
            ->assertJsonMissingPath('data.ticket.comments_json');
    }

    public function test_api_mutations_keep_csrf_protection(): void
    {
        // Laravel bypasses CSRF in tests; switch the environment to exercise it.
        $this->app->instance('env', 'local');
        $this->actingAs(User::factory()->create())->post('/api/tickets', [])
            ->assertStatus(419)->assertJsonStructure(['message']);
    }

    public function test_staff_can_replace_multiple_assignments_and_clear_them(): void
    {
        $user = User::factory()->create(['role' => 'dispatcher']);
        $this->mock(TicketStore::class, function (MockInterface $mock) use ($user) {
            $mock->shouldReceive('call')->once()->with('TicketUpdateAgents', [$user->id, 42, 1, 'open', 'high', '[2,3]'])->andReturn([]);
            $mock->shouldReceive('call')->once()->with('TicketUpdateAgents', [$user->id, 42, 2, 'open', 'high', '[]'])->andReturn([]);
        });
        $this->actingAs($user)->patchJson('/api/tickets/42', ['version' => 1, 'status' => 'open', 'priority' => 'high', 'assignee_ids' => [2, 3]])->assertOk();
        $this->patchJson('/api/tickets/42', ['version' => 2, 'status' => 'open', 'priority' => 'high', 'assignee_ids' => []])->assertOk();
    }

    public function test_agent_cannot_assign_and_status_updates_preserve_current_agents(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $this->mock(TicketStore::class, function (MockInterface $mock) use ($agent) {
            $mock->shouldReceive('call')->once()->with('TicketShow', [$agent->id, 42])
                ->andReturn([(object) ['priority' => 'high', 'assignees_json' => '[{"id":'.$agent->id.',"name":"Agent"}]']]);
            $mock->shouldReceive('call')->once()->with('TicketUpdateAgents', [$agent->id, 42, 1, 'in_progress', 'high', '['.$agent->id.']'])->andReturn([]);
        });

        $this->actingAs($agent)->patchJson('/api/tickets/42', [
            'version' => 1, 'status' => 'in_progress', 'priority' => 'high',
        ])->assertOk();
        $this->patchJson('/api/tickets/42', [
            'version' => 2, 'status' => 'in_progress', 'priority' => 'high', 'assignee_ids' => [],
        ])->assertForbidden();
    }

    public function test_agent_cannot_raise_ticket_priority(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $this->mock(TicketStore::class, function (MockInterface $mock) use ($agent) {
            $mock->shouldReceive('call')->once()->with('TicketShow', [$agent->id, 42])
                ->andReturn([(object) ['priority' => 'low', 'assignees_json' => '[{"id":'.$agent->id.'}]']]);
        });

        $this->actingAs($agent)->patchJson('/api/tickets/42', [
            'version' => 1, 'status' => 'in_progress', 'priority' => 'urgent',
        ])->assertForbidden();
    }

    public function test_assignment_array_rejects_duplicates_invalid_ids_and_ambiguous_input(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $base = ['version' => 1, 'status' => 'open', 'priority' => 'high'];
        foreach ([[2, 2], [0], ['invalid'], range(1, 51)] as $ids) {
            $this->patchJson('/api/tickets/42', $base + ['assignee_ids' => $ids])->assertUnprocessable();
        }
        $this->patchJson('/api/tickets/42', $base + ['assignee_ids' => [2], 'assignee_id' => 3])
            ->assertUnprocessable()->assertJsonValidationErrors('assignee_id');
    }
}
