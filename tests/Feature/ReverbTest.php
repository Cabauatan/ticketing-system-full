<?php

namespace Tests\Feature;

use App\Events\RealtimeChanged;
use App\Models\User;
use App\Services\TicketRealtime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ReverbTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret', 'broadcasting.connections.reverb.app_id' => 'test']);
        require base_path('routes/channels.php');
    }

    private function ticket(User $owner): int
    {
        return DB::table('tickets')->insertGetId([
            'requester_id' => $owner->id, 'subject' => 'Channel test', 'description' => 'Test',
            'category' => 'other', 'priority' => 'low', 'status' => 'new', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_workspace_channels_restrict_staff_and_personal_scope(): void
    {
        $user = User::factory()->create();
        $data = ['socket_id' => '123.456', 'channel_name' => 'private-workspace.staff'];
        $this->actingAs($user)->postJson('/broadcasting/auth', $data)->assertForbidden();
        $this->postJson('/broadcasting/auth', [...$data, 'channel_name' => 'private-workspace.user.'.$user->id])->assertOk();
        $this->postJson('/broadcasting/auth', [...$data, 'channel_name' => 'private-workspace.user.999999'])->assertForbidden();
        $agent = User::factory()->create(['role' => 'agent']);
        $this->actingAs($agent)->postJson('/broadcasting/auth', $data)->assertOk();
        DB::table('users')->where('id', $agent->id)->update(['role' => 'requester']);
        $this->postJson('/broadcasting/auth', $data)->assertForbidden();
    }

    public function test_dispatcher_can_join_staff_and_ticket_realtime_channels(): void
    {
        $owner = User::factory()->create();
        $dispatcher = User::factory()->create(['role' => 'dispatcher']);
        $ticket = $this->ticket($owner);

        $this->actingAs($dispatcher)->postJson('/broadcasting/auth', [
            'socket_id' => '123.456', 'channel_name' => 'private-workspace.staff',
        ])->assertOk();
        $this->postJson('/broadcasting/auth', [
            'socket_id' => '123.456', 'channel_name' => "private-tickets.$ticket.staff",
        ])->assertOk();
    }

    public function test_ticket_changes_refresh_staff_and_only_the_owner_workspace(): void
    {
        $owner = User::factory()->create();
        $ticket = $this->ticket($owner);
        Event::fake([RealtimeChanged::class]);
        app(TicketRealtime::class)->afterWrite('TicketUpdateAgents', [$owner->id, $ticket]);
        Event::assertDispatchedTimes(RealtimeChanged::class, 3);
        foreach (['private-workspace.staff', 'private-workspace.user.'.$owner->id] as $channel) {
            Event::assertDispatched(RealtimeChanged::class, fn ($event) => $event->broadcastOn()[0]->name === $channel
                && $event->broadcastAs() === 'workspace.changed' && $event->broadcastWith() === []);
        }
    }

    public function test_admin_changes_refresh_staff_without_exposing_user_details(): void
    {
        Event::fake([RealtimeChanged::class]);
        foreach (['AdminUsersChanged', 'AdminUserRole', 'AdminCategorySave', 'PermissionSet'] as $procedure) {
            app(TicketRealtime::class)->afterWrite($procedure, []);
        }
        Event::assertDispatchedTimes(RealtimeChanged::class, 4);
        Event::assertDispatched(RealtimeChanged::class, fn ($event) => $event->broadcastOn()[0]->name === 'private-workspace.staff'
            && $event->broadcastWith() === []);
    }

    public function test_user_account_changes_refresh_staff_and_the_affected_user(): void
    {
        Event::fake([RealtimeChanged::class]);
        app(TicketRealtime::class)->afterWrite('AdminUsersChanged', [42]);
        app(TicketRealtime::class)->afterWrite('AdminUserRole', [1, 42, 'agent']);

        Event::assertDispatchedTimes(RealtimeChanged::class, 4);
        Event::assertDispatched(RealtimeChanged::class, fn ($event) => $event->broadcastOn()[0]->name === 'private-workspace.user.42'
            && $event->broadcastAs() === 'account.changed' && $event->broadcastWith() === []);
        Event::assertDispatched(RealtimeChanged::class, fn ($event) => $event->broadcastOn()[0]->name === 'private-workspace.staff'
            && $event->broadcastAs() === 'workspace.changed' && $event->broadcastWith() === []);
    }

    public function test_channels_require_verified_session_and_ticket_scope(): void
    {
        $owner = User::factory()->create();
        $id = $this->ticket($owner);
        $data = ['socket_id' => '123.456', 'channel_name' => "private-tickets.$id"];
        $this->postJson('/broadcasting/auth', $data)->assertUnauthorized();
        $this->actingAs(User::factory()->unverified()->create())->postJson('/broadcasting/auth', $data)->assertForbidden();
        $this->actingAs(User::factory()->create())->postJson('/broadcasting/auth', $data)->assertForbidden();
        $this->actingAs($owner)->postJson('/broadcasting/auth', $data)->assertOk()->assertJsonStructure(['auth']);
        $this->postJson('/broadcasting/auth', [...$data, 'channel_name' => "private-tickets.$id.staff"])->assertForbidden();
        $agent = User::factory()->create(['role' => 'agent']);
        $this->actingAs($agent)->postJson('/broadcasting/auth', $data)->assertForbidden();
        DB::table('ticket_assignees')->insert(['ticket_id' => $id, 'user_id' => $agent->id]);
        $this->postJson('/broadcasting/auth', [...$data, 'channel_name' => "private-tickets.$id.staff"])->assertOk();
        DB::table('role_permissions')->where('role', 'agent')->where('permission', 'tickets.view')->update(['allowed' => false]);
        $this->postJson('/broadcasting/auth', $data)->assertForbidden();
    }

    public function test_agent_realtime_access_is_limited_to_assigned_tickets(): void
    {
        $owner = User::factory()->create();
        $agent = User::factory()->create(['role' => 'agent']);
        $assigned = $this->ticket($owner);
        $unassigned = $this->ticket($owner);
        $channel = ['socket_id' => '123.456', 'channel_name' => "private-tickets.$assigned"];

        $this->actingAs($agent)->postJson('/broadcasting/auth', $channel)->assertForbidden();
        DB::table('ticket_assignees')->insert(['ticket_id' => $assigned, 'user_id' => $agent->id]);
        $this->postJson('/broadcasting/auth', $channel)->assertOk();
        $this->postJson('/broadcasting/auth', [...$channel, 'channel_name' => "private-tickets.$unassigned"])->assertForbidden();
    }

    public function test_notification_channel_is_private_to_its_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-users.'.$user->id])->assertOk();
        $this->postJson('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-users.999'])->assertForbidden();
    }

    public function test_inventory_channel_requires_current_staff_permission(): void
    {
        $data = ['socket_id' => '123.456', 'channel_name' => 'private-inventory'];
        $this->postJson('/broadcasting/auth', $data)->assertUnauthorized();
        $this->actingAs(User::factory()->create())->postJson('/broadcasting/auth', $data)->assertForbidden();
        $this->actingAs(User::factory()->unverified()->create(['role' => 'agent']))->postJson('/broadcasting/auth', $data)->assertForbidden();
        $agent = User::factory()->create(['role' => 'agent']);
        $this->actingAs($agent)->postJson('/broadcasting/auth', $data)->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'superadmin']))->postJson('/broadcasting/auth', $data)->assertOk();
        $inventoryManager = User::factory()->create(['role' => 'inventory_manager']);
        $this->actingAs($inventoryManager)->postJson('/broadcasting/auth', $data)->assertOk();
    }

    public function test_inventory_writes_broadcast_only_an_empty_refresh_signal(): void
    {
        Event::fake([RealtimeChanged::class]);
        foreach (['AssetSave', 'AssetMove', 'AssetStockMove', 'AssetTicketLink'] as $procedure) {
            app(TicketRealtime::class)->afterWrite($procedure, [1, 42, 1, 'Private employee details']);
        }
        Event::assertDispatchedTimes(RealtimeChanged::class, 4);
        Event::assertDispatched(RealtimeChanged::class, fn ($event) => $event->broadcastOn()[0]->name === 'private-inventory'
            && $event->broadcastWith() === [] && $event->broadcastAs() === 'inventory.changed');
    }

    public function test_reverb_outage_does_not_fail_a_committed_inventory_write(): void
    {
        Event::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('Offline'));
        Log::shouldReceive('warning')->once();
        app(TicketRealtime::class)->afterWrite('AssetMove', [1, 42, 1, 'deploy']);
        $this->assertTrue(true);
    }

    public function test_internal_changes_only_signal_staff_and_never_include_message_bodies(): void
    {
        Event::fake([RealtimeChanged::class]);
        app(TicketRealtime::class)->afterWrite('TicketComment', [1, 42, 'Confidential note', 1]);
        Event::assertDispatched(RealtimeChanged::class, fn ($event) => $event->broadcastOn()[0]->name === 'private-tickets.42.staff'
            && $event->broadcastWith() === [] && $event->broadcastAs() === 'chat.changed');
        Event::assertDispatchedTimes(RealtimeChanged::class, 1);
    }

    public function test_reverb_outage_does_not_fail_a_committed_message(): void
    {
        Event::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('Offline'));
        Log::shouldReceive('warning')->once();
        app(TicketRealtime::class)->afterWrite('TicketComment', [1, 42, 'Message', 0]);
        $this->assertTrue(true);
    }
}
