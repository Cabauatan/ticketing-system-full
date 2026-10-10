<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TicketStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\TestCase;

class PermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_cannot_change_permission_matrix(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->getJson('/api/admin/permissions')->assertForbidden();
        $this->putJson('/api/admin/permissions/agent', ['version' => 1, 'permissions' => []])->assertForbidden();
    }

    public function test_superadmin_permission_update_validates_role_boundary(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'superadmin']));
        $this->putJson('/api/admin/permissions/superadmin', ['version' => 1, 'permissions' => []])->assertNotFound();
        $this->putJson('/api/admin/permissions/requester', ['version' => 1, 'permissions' => ['users.roles']])
            ->assertUnprocessable()->assertJsonValidationErrors('permissions.0');
        $this->putJson('/api/admin/permissions/agent', ['version' => 1, 'permissions' => ['tickets.view', 'tickets.view']])->assertUnprocessable();
    }

    public function test_superadmin_updates_using_authenticated_actor_and_version(): void
    {
        $user = User::factory()->create(['role' => 'superadmin']);
        $this->mock(TicketStore::class, fn (MockInterface $mock) => $mock->shouldReceive('call')->once()
            ->with('PermissionSet', [$user->id, 'agent', 3, '["tickets.view"]'])
            ->andReturn([(object) ['role' => 'agent', 'version' => 4]]));
        $this->actingAs($user)->putJson('/api/admin/permissions/agent', [
            'actor' => 999, 'version' => 3, 'permissions' => ['tickets.view'],
        ])->assertOk()->assertJsonPath('data.version', 4);
    }

    public function test_revoked_export_is_denied_while_dashboard_remains_available(): void
    {
        DB::table('role_permissions')->where('role', 'agent')->where('permission', 'reports.export')->update(['allowed' => false]);
        $this->actingAs(User::factory()->create(['role' => 'agent']));
        $this->getJson('/api/reports/tickets.xlsx')->assertForbidden();
        $this->getJson('/api/dashboard')->assertOk();
        $this->getJson('/api/permissions')->assertOk()->assertJsonMissing(['reports.export']);
    }

    public function test_revoked_internal_notes_and_ticket_updates_are_denied_before_procedure_call(): void
    {
        DB::table('role_permissions')->where('role', 'agent')->whereIn('permission', ['tickets.update', 'tickets.internal'])->update(['allowed' => false]);
        $this->mock(TicketStore::class, fn (MockInterface $mock) => $mock->shouldNotReceive('call'));
        $this->actingAs(User::factory()->create(['role' => 'agent']));
        $this->patchJson('/api/tickets/10', [])->assertForbidden();
        $this->postJson('/api/tickets/10/comments', ['body' => 'Note', 'internal' => true])->assertForbidden();
    }

    public function test_live_role_changes_are_reflected_without_relogin(): void
    {
        $user = User::factory()->create(['role' => 'superadmin']);
        $this->actingAs($user);
        DB::table('users')->where('id', $user->id)->update(['role' => 'requester']);
        $this->getJson('/api/admin/permissions')->assertForbidden();
    }

    public function test_permissions_are_combined_across_multiple_roles(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        DB::table('user_roles')->insert(['user_id' => $agent->id, 'role' => 'dispatcher']);

        $permissions = app(\App\Services\TicketPermissions::class)->forUser($agent);
        $this->assertContains('tickets.view', $permissions);
        $this->assertContains('tickets.update', $permissions);
        $this->assertContains('reports.view', $permissions);
        $this->assertContains('reports.export', $permissions);
    }

    public function test_dispatcher_can_update_tickets_without_admin_or_inventory_access(): void
    {
        $dispatcher = User::factory()->create(['role' => 'dispatcher']);
        $this->mock(TicketStore::class, fn (MockInterface $mock) => $mock->shouldReceive('call')->once()
            ->with('TicketUpdateAgents', [$dispatcher->id, 10, 1, 'open', 'medium', '[]'])->andReturn([]));

        $this->actingAs($dispatcher)->patchJson('/api/tickets/10', [
            'version' => 1, 'status' => 'open', 'priority' => 'medium', 'assignee_ids' => [],
        ])->assertOk();
        $this->getJson('/api/admin')->assertForbidden();
        $this->getJson('/api/inventory')->assertForbidden();
        $this->assertContains('tickets.update', app(\App\Services\TicketPermissions::class)->forUser($dispatcher));
        $this->assertNotContains('users.roles', app(\App\Services\TicketPermissions::class)->forUser($dispatcher));
        $this->assertNotContains('inventory.manage', app(\App\Services\TicketPermissions::class)->forUser($dispatcher));
    }

    public function test_inventory_manager_gets_inventory_access_without_ticket_or_admin_access(): void
    {
        $inventoryManager = User::factory()->create(['role' => 'inventory_manager']);

        $this->actingAs($inventoryManager)->getJson('/api/inventory')->assertOk();
        $this->getJson('/api/reports/inventory')->assertOk();
        $this->getJson('/api/tickets')->assertForbidden();
        $this->getJson('/api/admin')->assertForbidden();
        $permissions = app(\App\Services\TicketPermissions::class)->forUser($inventoryManager);
        $this->assertContains('inventory.manage', $permissions);
        $this->assertNotContains('tickets.view', $permissions);
        $this->assertNotContains('users.roles', $permissions);
    }
}
