<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TicketStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_requesters_and_agents_cannot_administer_users_or_categories(): void
    {
        foreach (['requester', 'agent'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->getJson('/api/admin')->assertForbidden();
            $this->patchJson('/api/admin/users/999/role', ['roles' => ['admin']])->assertForbidden();
            $this->postJson('/api/admin/categories', ['slug' => 'other'])->assertForbidden();
        }
    }

    public function test_admin_page_uses_authenticated_admin_scope(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->mock(TicketStore::class, function (MockInterface $mock) use ($admin) {
            $mock->shouldReceive('call')->with('AdminUsers', [$admin->id, '', 1])->once()->andReturn([]);
            $mock->shouldReceive('call')->with('TicketCategories', [$admin->id, 1])->once()->andReturn([]);
            $mock->shouldReceive('call')->with('TicketAgents', [$admin->id])->once()->andReturn([]);
            $mock->shouldReceive('call')->with('AdminAudit', [$admin->id])->once()->andReturn([]);
        });
        $this->actingAs($admin)->getJson('/api/admin')->assertOk()->assertJsonStructure([
            'data' => ['users', 'categories', 'agents', 'audit'],
        ]);
    }

    public function test_admin_cannot_demote_self_and_invalid_roles_are_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->patchJson('/api/admin/users/'.$admin->id.'/role', ['roles' => ['requester']])->assertStatus(422);
        $this->patchJson('/api/admin/users/999/role', ['roles' => ['superuser']])->assertJsonValidationErrors('roles.0');
    }

    public function test_user_can_receive_agent_and_dispatcher_roles_together(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'requester']);
        $this->mock(TicketStore::class, fn (MockInterface $mock) => $mock->shouldReceive('call')->once()
            ->with('AdminUserRole', [$admin->id, $target->id, '["agent","dispatcher"]'])->andReturn([]));

        $this->actingAs($admin)->patchJson('/api/admin/users/'.$target->id.'/role', [
            'roles' => ['agent', 'dispatcher'],
        ])->assertOk()->assertJsonPath('message', 'Saved.');
    }

    public function test_admin_can_assign_roles_but_cannot_grant_superadmin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'requester']);
        $this->mock(TicketStore::class, fn (MockInterface $mock) => $mock->shouldReceive('call')->never());

        $this->actingAs($admin)->patchJson('/api/admin/users/'.$target->id.'/role', [
            'roles' => ['superadmin', 'agent'],
        ])->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $target->id, 'role' => 'requester']);
    }

    public function test_admin_cannot_change_roles_on_an_existing_superadmin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $superadmin = User::factory()->create(['role' => 'superadmin']);
        $this->mock(TicketStore::class, fn (MockInterface $mock) => $mock->shouldReceive('call')->never());

        $this->actingAs($admin)->patchJson('/api/admin/users/'.$superadmin->id.'/role', [
            'roles' => ['admin', 'agent'],
        ])->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $superadmin->id, 'role' => 'superadmin']);
    }

    public function test_multi_role_admin_can_assign_roles(): void
    {
        $admin = User::factory()->create(['role' => 'dispatcher']);
        DB::table('user_roles')->insert(['user_id' => $admin->id, 'role' => 'admin']);
        $target = User::factory()->create(['role' => 'requester']);
        $this->mock(TicketStore::class, fn (MockInterface $mock) => $mock->shouldReceive('call')->once()
            ->with('AdminUserRole', [$admin->id, $target->id, '["inventory_manager","agent"]'])->andReturn([]));

        $this->actingAs($admin)->patchJson('/api/admin/users/'.$target->id.'/role', [
            'roles' => ['inventory_manager', 'agent'],
        ])->assertOk();
    }

    public function test_category_save_validates_code_and_uses_procedure(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->mock(TicketStore::class, fn (MockInterface $mock) => $mock->shouldReceive('call')
            ->with('AdminCategorySave', [$admin->id, 'email-support', 'Email support', 1, null])->once()->andReturn([]));
        $this->actingAs($admin)->postJson('/api/admin/categories', ['slug' => '../bad', 'name' => 'Test', 'active' => true])->assertJsonValidationErrors('slug');
        $this->postJson('/api/admin/categories', ['slug' => 'email-support', 'name' => 'Email support', 'active' => true])->assertOk()->assertJsonPath('message', 'Saved.');
    }

    public function test_bootstrap_local_verification_is_rejected_outside_local_environment(): void
    {
        $this->artisan('tickets:bootstrap-admin', ['email' => 'test@example.test', '--verify-local' => true])->assertFailed();
    }

    public function test_requester_reopen_uses_authenticated_actor(): void
    {
        $user = User::factory()->create();
        $this->mock(TicketStore::class, fn (MockInterface $mock) => $mock->shouldReceive('call')
            ->with('TicketReopen', [$user->id, 20])->once()->andReturn([]));
        $this->actingAs($user)->postJson('/api/tickets/20/reopen', ['actor' => 999])->assertOk()->assertJsonPath('message', 'Saved.');
    }

    public function test_sla_command_invokes_batch_procedure(): void
    {
        $this->mock(TicketStore::class, function (MockInterface $mock) {
            $mock->shouldReceive('ready')->andReturn(true);
            $mock->shouldReceive('call')->with('TicketEscalate', [])->once()->andReturn([(object) ['processed' => 3]]);
        });
        $this->artisan('tickets:process-sla')->expectsOutput('Processed 3 ticket SLA/priority escalations.')->assertSuccessful();
    }

    public function test_administrator_cannot_delete_own_account(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->deleteJson('/api/settings/profile', ['password' => 'password'])->assertJsonValidationErrors('password');
        $this->assertAuthenticatedAs($admin);
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_user_with_ticket_history_cannot_delete_account(): void
    {
        $user = User::factory()->create();
        DB::table('tickets')->insert([
            'requester_id' => $user->id, 'subject' => 'Retain history', 'description' => 'Test',
            'category' => 'other', 'priority' => 'low', 'status' => 'new',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($user)->deleteJson('/api/settings/profile', ['password' => 'password'])->assertJsonValidationErrors('password');
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }
}
