<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\TicketPermissions;
use App\Services\TicketStore;
use Tests\TestCase;

class TicketCategoryApiTest extends TestCase
{
    public function test_ticket_creators_can_load_custom_active_categories_without_ticket_list_access(): void
    {
        $user = (new User)->forceFill(['id' => 42, 'email' => 'creator@example.test', 'role' => 'requester', 'email_verified_at' => now()]);
        $this->mock(TicketPermissions::class, fn ($mock) => $mock->shouldReceive('forUser')->andReturn(['tickets.create']));
        $this->mock(TicketStore::class, fn ($mock) => $mock->shouldReceive('call')->once()
            ->with('TicketCategories', [42, 0])->andReturn([(object) ['slug' => 'printer_repair', 'name' => 'Printer repair', 'active' => 1]]));

        $this->actingAs($user)->getJson('/api/ticket-categories')->assertOk()
            ->assertJsonPath('data.categories.0.slug', 'printer_repair')
            ->assertJsonPath('data.categories.0.name', 'Printer repair');
    }

    public function test_user_without_ticket_create_permission_cannot_load_categories(): void
    {
        $user = (new User)->forceFill(['id' => 42, 'email' => 'viewer@example.test', 'email_verified_at' => now()]);
        $this->mock(TicketPermissions::class, fn ($mock) => $mock->shouldReceive('forUser')->andReturn(['tickets.view']));
        $this->mock(TicketStore::class, fn ($mock) => $mock->shouldNotReceive('call'));

        $this->actingAs($user)->getJson('/api/ticket-categories')->assertForbidden();
    }

    public function test_guests_cannot_load_categories(): void
    {
        $this->getJson('/api/ticket-categories')->assertUnauthorized();
    }
}
