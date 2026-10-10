<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ExcelReport;
use App\Services\TicketStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Concerns\ReadsExcelExports;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use ReadsExcelExports;
    use RefreshDatabase;

    public function test_report_uses_authenticated_actor_and_validated_period(): void
    {
        $user = User::factory()->create();
        $this->mock(TicketStore::class, function (MockInterface $mock) use ($user) {
            $mock->shouldReceive('ready')->andReturn(true);
            $mock->shouldReceive('call')->once()->with('TicketReport', [$user->id, 7])
                ->andReturn([(object) ['dimension' => 'status', 'label' => 'open', 'total' => 2]]);
        });
        $this->actingAs($user)->getJson('/api/dashboard?days=7&actor=999')
            ->assertOk()->assertJsonPath('data.days', 7)->assertJsonPath('data.isStaff', false)
            ->assertJsonPath('data.report.0.total', 2);
    }

    public function test_report_rejects_invalid_period(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/dashboard?days=-1')->assertJsonValidationErrors('days');
        $this->getJson('/api/reports/tickets.xlsx?days=999')->assertJsonValidationErrors('days');
    }

    public function test_guests_cannot_export(): void
    {
        $this->getJson('/api/reports/tickets.xlsx')->assertUnauthorized();
    }

    public function test_export_quotes_cells_and_neutralizes_formulas(): void
    {
        $user = User::factory()->create();
        $this->mock(TicketStore::class, function (MockInterface $mock) use ($user) {
            $mock->shouldReceive('call')->once()->with('TicketReportExport', [$user->id, 0])
                ->andReturn([(object) ['dimension' => 'workload', 'label' => '=HYPERLINK("test")', 'total' => 3]]);
        });
        $response = $this->actingAs($user)->get('/api/reports/tickets.xlsx?days=0');
        $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $response->assertHeader('Content-Type', ExcelReport::MIME);
        $parts = $this->excelParts($response->streamedContent());
        $sheet = $parts['xl/worksheets/sheet1.xml'];
        $this->assertStringContainsString('=HYPERLINK(', $sheet);
        $this->assertStringNotContainsString('<f', $sheet);
        $this->assertStringContainsString('<v>3</v>', $sheet);
    }

    public function test_guests_are_redirected_to_the_login_page()
    {
        $response = $this->getJson(route('dashboard'));
        $response->assertUnauthorized();
    }

    public function test_authenticated_users_can_visit_the_dashboard()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->getJson(route('dashboard'));
        $response->assertOk()->assertJsonStructure(['data']);
    }
}
