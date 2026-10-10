<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ExcelReport;
use App\Services\TicketStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\Concerns\ReadsExcelExports;
use Tests\TestCase;

class ReportTest extends TestCase
{
    use ReadsExcelExports;
    use RefreshDatabase;

    public function test_reports_require_authentication_and_verification(): void
    {
        $this->getJson('/api/reports')->assertUnauthorized();
        $this->get('/api/reports')->assertUnauthorized();
        $this->getJson('/api/reports/export.xlsx')->assertUnauthorized();
        $this->actingAs(User::factory()->unverified()->create())->get('/api/reports')->assertForbidden();
    }

    public function test_report_uses_session_actor_and_decodes_sections(): void
    {
        $user = User::factory()->create();
        $this->mock(TicketStore::class, function (MockInterface $mock) use ($user) {
            $mock->shouldReceive('ready')->andReturn(true);
            $mock->shouldReceive('call')->twice()->with('TicketAnalytics', [$user->id, '2026-09-01', '2026-09-30'])
                ->andReturn([(object) ['summary_json' => '{"total":2}', 'trend_json' => '[{"date":"2026-09-02","total":1},{"date":"2026-09-01","total":1}]']]);
        });
        $this->actingAs($user)->getJson('/api/reports?from=2026-09-01&to=2026-09-30&actor=999')
            ->assertOk()->assertJsonPath('data.summary.total', 2)->assertJsonPath('data.isStaff', false)
            ->assertJsonPath('data.trend.0.date', '2026-09-01');
        $this->get('/api/reports?from=2026-09-01&to=2026-09-30')->assertOk()->assertJsonPath('data.summary.total', 2);
    }

    public function test_inventory_report_summarizes_units_cost_and_group_breakdowns(): void
    {
        $user = User::factory()->create(['role' => 'agent']);
        $rows = [
            (object) ['asset_tag' => 'PC-01', 'name' => 'Desktop', 'type' => 'desktop', 'status' => 'available', 'division' => 'IT', 'quantity' => 2, 'unit_cost' => '500.00'],
            (object) ['asset_tag' => 'MN-01', 'name' => 'Monitor', 'type' => 'monitor', 'status' => 'deployed', 'division' => 'IT', 'quantity' => 1, 'unit_cost' => null],
            (object) ['asset_tag' => 'SUP-01', 'name' => 'Paper', 'type' => 'consumable', 'status' => 'out_of_stock', 'division' => null, 'quantity' => 0, 'unit_cost' => '10.00'],
        ];
        $this->mock(TicketStore::class, function (MockInterface $mock) use ($user, $rows) {
            $mock->shouldReceive('ready')->once()->andReturn(true);
            $mock->shouldReceive('call')->once()->with('AssetList', [$user->id, '', '', '', 0])->andReturn($rows);
        });

        $this->actingAs($user)->getJson('/api/reports/inventory')->assertOk()
            ->assertJsonPath('data.summary.asset_records', 3)
            ->assertJsonPath('data.summary.tracked_units', 3)
            ->assertJsonPath('data.summary.deployed_units', 1)
            ->assertJsonPath('data.summary.available_units', 2)
            ->assertJsonPath('data.summary.recorded_value', 1000)
            ->assertJsonPath('data.summary.assets_without_cost', 1)
            ->assertJsonPath('data.by_type.0.label', 'consumable')
            ->assertJsonPath('data.by_division.0.label', 'IT');
    }

    public function test_inventory_report_export_contains_summary_breakdowns_and_asset_register(): void
    {
        $user = User::factory()->create(['role' => 'agent']);
        $rows = [(object) [
            'asset_tag' => '=ASSET()', 'serial_number' => 'SN-01', 'name' => 'Laptop', 'type' => 'laptop',
            'brand' => 'Test', 'model' => 'X1', 'specifications' => null, 'quantity' => 1, 'unit_cost' => '25000.00',
            'status' => 'deployed', 'holder_name' => 'Agent', 'employee_code' => null, 'department' => 'IT', 'division' => 'Operations',
            'location' => 'Floor 2', 'accessories' => null, 'deployed_at' => null, 'purchase_date' => '2026-01-10', 'warranty_until' => null,
        ]];
        $this->mock(TicketStore::class, function (MockInterface $mock) use ($user, $rows) {
            $mock->shouldReceive('call')->once()->with('AssetExport', [$user->id, '', '', ''])->andReturn($rows);
        });

        $response = $this->actingAs($user)->get('/api/reports/inventory.xlsx');
        $response->assertOk()->assertHeader('Content-Type', ExcelReport::MIME);
        $parts = $this->excelParts($response->streamedContent());
        foreach (['Summary', 'By status', 'By type', 'By division', 'Asset register'] as $name) {
            $this->assertStringContainsString('name="'.$name.'"', $parts['xl/workbook.xml']);
        }
        $assetSheet = $parts['xl/worksheets/sheet5.xml'];
        $this->assertStringContainsString('=ASSET()', $assetSheet);
        $this->assertStringNotContainsString('<f', $assetSheet);
        $this->assertStringContainsString('<v>46032</v>', $assetSheet);
    }

    public function test_inventory_report_requires_inventory_view_permission(): void
    {
        $requester = User::factory()->create(['role' => 'requester']);

        $this->actingAs($requester)->getJson('/api/reports/inventory')->assertForbidden();
    }

    public function test_invalid_and_excessive_ranges_are_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        foreach (['from=wrong', 'from=2026-02-30', 'from=2026-10-02&to=2026-10-01', 'from=2024-01-01&to=2026-01-01'] as $query) {
            $this->getJson('/api/reports?'.$query)->assertUnprocessable();
            $this->getJson('/api/reports/export.xlsx?'.$query)->assertUnprocessable();
        }
    }

    public function test_revoked_view_and_export_permissions_apply_to_new_routes(): void
    {
        $user = User::factory()->create(['role' => 'agent']);
        DB::table('role_permissions')->where('role', 'agent')->whereIn('permission', ['reports.view', 'reports.export'])->update(['allowed' => false]);
        $this->actingAs($user)->getJson('/api/reports')->assertForbidden();
        $this->get('/api/reports')->assertForbidden();
        $this->getJson('/api/reports/export.xlsx')->assertForbidden();
        $this->get('/api/reports/export.xlsx')->assertForbidden();
    }

    public function test_export_has_sections_and_escapes_spreadsheet_formulas(): void
    {
        $user = User::factory()->create(['role' => 'agent']);
        $this->mock(TicketStore::class, function (MockInterface $mock) use ($user) {
            $mock->shouldReceive('call')->once()->with('TicketAnalyticsExport', [$user->id, '2026-09-01', '2026-09-30'])
                ->andReturn([(object) [
                    'summary_json' => '{"total":1}',
                    'agents_json' => json_encode([['id' => 2, 'name' => '=FORMULA()', 'assigned' => 1, 'active' => 1, 'completed' => 0, 'overdue' => 0, 'public_replies' => 1, 'tickets_replied' => 1, 'first_replies' => 1, 'first_reply_minutes' => 15]]),
                ]]);
        });
        $response = $this->actingAs($user)->get('/api/reports/export.xlsx?from=2026-09-01&to=2026-09-30');
        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $response->assertHeader('Content-Type', ExcelReport::MIME);
        $parts = $this->excelParts($response->streamedContent());
        foreach (['Summary', 'Breakdowns', 'Daily trend', 'Agent performance'] as $name) {
            $this->assertStringContainsString('name="'.$name.'"', $parts['xl/workbook.xml']);
        }
        $agents = $parts['xl/worksheets/sheet4.xml'];
        $this->assertStringContainsString('=FORMULA()', $agents);
        $this->assertStringNotContainsString('<f', $agents);
        $this->assertStringContainsString('autoFilter', $agents);
        $this->assertStringContainsString('state="frozen"', $agents);
        $this->assertStringContainsString('ySplit="5"', $agents);
        $this->assertStringContainsString('<v>15</v>', $agents);
        $this->assertStringContainsString('2026-09-01', $parts['xl/worksheets/sheet1.xml']);
        $this->assertStringContainsString('r="A35"', $parts['xl/worksheets/sheet3.xml']);
    }

    public function test_requester_excel_omits_agent_sheet_and_preserves_empty_report(): void
    {
        $user = User::factory()->create();
        $this->mock(TicketStore::class, function (MockInterface $mock) use ($user) {
            $mock->shouldReceive('call')->once()->with('TicketAnalyticsExport', [$user->id, '2026-09-01', '2026-09-01'])
                ->andReturn([(object) ['summary_json' => '{"total":0}']]);
        });
        $response = $this->actingAs($user)->get('/api/reports/export.xlsx?from=2026-09-01&to=2026-09-01');
        $response->assertOk();
        $parts = $this->excelParts($response->streamedContent());
        $this->assertStringNotContainsString('Agent performance', $parts['xl/workbook.xml']);
        $this->assertStringContainsString('<v>0</v>', $parts['xl/worksheets/sheet1.xml']);
        $this->assertStringContainsString('<v>0</v>', $parts['xl/worksheets/sheet3.xml']);
    }
}
