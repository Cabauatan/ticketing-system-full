<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TicketStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class SuperadminTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_use_admin_api(): void
    {
        $user = User::factory()->create(['role' => 'superadmin']);
        $this->mock(TicketStore::class, function (MockInterface $mock) use ($user) {
            $mock->shouldReceive('call')->with('AdminUsers', [$user->id, '', 1])->once()->andReturn([]);
            $mock->shouldReceive('call')->with('TicketCategories', [$user->id, 1])->once()->andReturn([]);
            $mock->shouldReceive('call')->with('TicketAgents', [$user->id])->once()->andReturn([]);
            $mock->shouldReceive('call')->with('AdminAudit', [$user->id])->once()->andReturn([]);
        });
        $this->actingAs($user)->getJson('/api/admin')->assertOk();
    }

    public function test_superadmin_can_manage_tickets(): void
    {
        $user = User::factory()->create(['role' => 'superadmin']);
        $this->mock(TicketStore::class, function (MockInterface $mock) use ($user) {
            $mock->shouldReceive('call')->with('TicketShow', [$user->id, 42])->once()
                ->andReturn([(object) ['assignees_json' => '[]']]);
            $mock->shouldReceive('call')->with('TicketUpdateAgents', [$user->id, 42, 1, 'open', 'high', '[]'])->once()->andReturn([]);
        });
        $this->actingAs($user)->patchJson('/api/tickets/42', ['version' => 1, 'status' => 'open', 'priority' => 'high'])->assertOk();
    }
}
