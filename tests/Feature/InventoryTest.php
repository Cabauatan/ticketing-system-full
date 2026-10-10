<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ExcelReport;
use App\Services\TicketStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\Concerns\ReadsExcelExports;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use ReadsExcelExports, RefreshDatabase;

    public function test_inventory_is_staff_only_and_requires_verification(): void
    {
        $this->get('/api/inventory')->assertUnauthorized();
        $this->actingAs(User::factory()->unverified()->create(['role' => 'agent']))->get('/api/inventory')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'agent']))->get('/api/inventory')->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/api/inventory')->assertForbidden();
        $this->postJson('/api/inventory', [])->assertForbidden();
        $this->get('/api/inventory/export.xlsx')->assertForbidden();
        $this->get('/api/tickets/1/assets')->assertForbidden();
    }

    public function test_staff_create_uses_session_actor_and_validated_fields(): void
    {
        $user = User::factory()->create(['role' => 'inventory_manager']);
        $this->mock(TicketStore::class, function (MockInterface $mock) use ($user) {
            $mock->shouldReceive('call')->once()->withArgs(function ($proc, $args) use ($user) {
                $data = json_decode($args[3], true);

                return $proc === 'AssetSave' && $args[0] === $user->id && $args[1] === null && $data['asset_tag'] === 'PC-001'
                    && $data['quantity'] === 1 && ! isset($data['status'], $data['actor_id']);
            })->andReturn([(object) ['id' => 12]]);
        });
        $this->actingAs($user)->postJson('/api/inventory', ['asset_tag' => 'PC-001', 'name' => 'Office PC', 'type' => 'desktop', 'actor_id' => 999, 'status' => 'deployed'])
            ->assertCreated()->assertJsonPath('data.id', 12);
    }

    public function test_asset_and_movement_validation(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/inventory', [])->assertUnprocessable()->assertJsonValidationErrors(['asset_tag', 'name', 'type']);
        $this->postJson('/api/inventory', ['asset_tag' => 'A', 'name' => 'PC', 'type' => 'desktop', 'purchase_date' => '2026-01-02', 'warranty_until' => '2026-01-01'])->assertUnprocessable()->assertJsonValidationErrors('warranty_until');
        $this->patchJson('/api/inventory/1', ['asset_tag' => 'A', 'name' => 'PC', 'type' => 'desktop'])->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->postJson('/api/inventory/1/movements', ['version' => 1, 'action' => 'deploy'])->assertUnprocessable()->assertJsonValidationErrors(['holder_name', 'department', 'location', 'note']);
        $this->postJson('/api/inventory/1/movements', ['version' => 1, 'action' => 'delete', 'location' => 'IT', 'note' => 'Test'])->assertUnprocessable();
    }

    public function test_serialized_assets_must_be_saved_one_per_serial_number(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'inventory_manager']))
            ->postJson('/api/inventory', [
                'asset_tag' => 'MON-001', 'name' => 'Monitor', 'type' => 'monitor',
                'serial_number' => 'SERIAL-001', 'quantity' => 2,
            ])
            ->assertUnprocessable()->assertJsonValidationErrors('quantity');
    }

    public function test_consumables_use_stock_quantity_without_serial_numbers(): void
    {
        $user = User::factory()->create(['role' => 'inventory_manager']);
        $this->mock(TicketStore::class, function (MockInterface $mock) use ($user) {
            $mock->shouldReceive('call')->once()->withArgs(function ($procedure, $args) use ($user) {
                $data = json_decode($args[3], true);

                return $procedure === 'AssetSave' && $args[0] === $user->id
                    && $data['type'] === 'consumable' && $data['quantity'] === 0
                    && empty($data['serial_number']);
            })->andReturn([(object) ['id' => 35]]);
        });

        $this->actingAs($user)->postJson('/api/inventory', [
            'asset_tag' => 'SUP-PAPER-A4', 'name' => 'A4 paper', 'type' => 'consumable', 'quantity' => 0,
        ])->assertCreated()->assertJsonPath('data.id', 35);

        $this->actingAs($user)->postJson('/api/inventory', [
            'asset_tag' => 'SUP-TONER-01', 'name' => 'Printer toner', 'type' => 'consumable', 'quantity' => 4, 'serial_number' => 'TONER-123',
        ])->assertUnprocessable()->assertJsonValidationErrors('serial_number');
    }

    public function test_consumable_stock_actions_require_recipient_for_issue_and_use_stock_procedure(): void
    {
        $user = User::factory()->create(['role' => 'inventory_manager']);
        $this->mock(TicketStore::class, function (MockInterface $mock) use ($user) {
            foreach ([['restock', 20, null], ['issue', 3, 'Marcel Cabauatan']] as [$action, $quantity, $recipient]) {
                $mock->shouldReceive('call')->once()->withArgs(function ($procedure, $args) use ($user, $action, $quantity, $recipient) {
                    $data = json_decode($args[5], true);

                    return $procedure === 'AssetStockMove' && $args[0] === $user->id && $args[1] === 41
                        && $args[2] === 7 && $args[3] === $action && $args[4] === $quantity
                        && ($data['recipient'] ?? null) === $recipient;
                })->andReturn([]);
            }
        });

        $this->actingAs($user)->postJson('/api/inventory/41/stock', [
            'version' => 7, 'action' => 'issue', 'quantity' => 3, 'note' => 'Issued for workstation setup',
        ])->assertUnprocessable()->assertJsonValidationErrors('recipient');
        $this->postJson('/api/inventory/41/stock', [
            'version' => 7, 'action' => 'restock', 'quantity' => 20, 'note' => 'Received from supplier',
        ])->assertOk()->assertJsonPath('message', 'Consumable stock replenished.');
        $this->postJson('/api/inventory/41/stock', [
            'version' => 7, 'action' => 'issue', 'quantity' => 3, 'recipient' => 'Marcel Cabauatan', 'note' => 'Issued for workstation setup',
        ])->assertOk()->assertJsonPath('message', 'Consumable stock issued.');
    }

    public function test_inventory_purchase_proof_is_stored_privately_and_registered(): void
    {
        Storage::fake('attachments');
        $user = User::factory()->create(['role' => 'inventory_manager']);
        $this->mock(TicketStore::class, function (MockInterface $mock) use ($user) {
            $mock->shouldReceive('call')->once()->with('AssetAttachmentList', [$user->id, 12])->andReturn([]);
            $mock->shouldReceive('call')->once()->withArgs(function ($procedure, $args) use ($user) {
                return $procedure === 'AssetAttachmentAdd' && $args[0] === $user->id && $args[1] === 12
                    && $args[2] === 'receipt.pdf' && str_starts_with($args[3], 'inventory/12/') && $args[5] > 0;
            })->andReturn([(object) ['id' => 25]]);
        });

        $this->actingAs($user)->postJson('/api/inventory/12/attachments', [
            'file' => UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf'),
        ])->assertCreated()->assertJsonPath('data.id', 25);

        $this->assertCount(1, Storage::disk('attachments')->allFiles('inventory/12'));
    }

    public function test_lifecycle_actions_are_passed_to_the_procedure_with_the_authenticated_actor(): void
    {
        $user = User::factory()->create(['role' => 'inventory_manager']);
        $this->mock(TicketStore::class, function (MockInterface $mock) use ($user) {
            foreach (['return', 'damage', 'replace'] as $action) {
                $mock->shouldReceive('call')->once()->withArgs(fn ($procedure, $args) => $procedure === 'AssetMove'
                    && $args[0] === $user->id && $args[1] === 12 && $args[2] === 3 && $args[3] === $action
                    && json_decode($args[4], true)['note'] === 'Recorded equipment condition')->andReturn([]);
            }
        });
        foreach (['return', 'damage', 'replace'] as $action) {
            $this->actingAs($user)->postJson('/api/inventory/12/movements', [
                'version' => 3, 'action' => $action, 'location' => 'IT storage', 'note' => 'Recorded equipment condition',
            ])->assertOk();
        }
    }

    public function test_agent_cannot_access_inventory(): void
    {
        $user = User::factory()->create(['role' => 'agent']);
        $this->actingAs($user)->get('/api/inventory')->assertForbidden();
        $this->postJson('/api/inventory', [])->assertForbidden();
        $this->get('/api/inventory/export.xlsx')->assertForbidden();
        $this->getJson('/api/tickets/1/assets')->assertForbidden();
        $this->assertSame([], DB::table('role_permissions')->where('role', 'agent')->where('permission', 'like', 'inventory.%')->pluck('permission')->all());
    }

    public function test_linking_requires_ticket_update_permission(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        DB::table('role_permissions')->where('role', 'admin')->where('permission', 'tickets.update')->update(['allowed' => false]);
        $this->actingAs($user)->postJson('/api/inventory/1/tickets', ['version' => 1, 'ticket_id' => 1, 'link' => true])->assertForbidden();
    }

    public function test_export_generates_excel_and_applies_filters(): void
    {
        $user = User::factory()->create(['role' => 'inventory_manager']);
        $this->mock(TicketStore::class, fn (MockInterface $mock) => $mock->shouldReceive('call')->once()->with('AssetExport', [$user->id, 'PC', 'deployed', 'desktop'])->andReturn([]));
        $response = $this->actingAs($user)->get('/api/inventory/export.xlsx?search=PC&status=deployed&type=desktop');
        $response->assertOk()->assertHeader('Content-Type', ExcelReport::MIME);
        $parts = $this->excelParts($response->streamedContent());
        $this->assertStringContainsString('name="Inventory"', $parts['xl/workbook.xml']);
        $this->assertStringContainsString('status deployed', $parts['xl/worksheets/sheet1.xml']);
        $this->assertStringContainsString('Division', $parts['xl/worksheets/sheet1.xml']);
        $this->assertStringContainsString('Quantity', $parts['xl/worksheets/sheet1.xml']);
        $this->assertStringContainsString('Total value (PHP)', $parts['xl/worksheets/sheet1.xml']);
    }
}
