<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsToTicketRequests;
use App\Services\ExcelReport;
use App\Services\TicketPermissions;
use App\Services\TicketStore;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    use RespondsToTicketRequests;

    /** @return array{from: string, to: string} */
    private function period(Request $request): array
    {
        $dates = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $to = $dates['to'] ?? now()->utc()->toDateString();
        $from = $dates['from'] ?? CarbonImmutable::parse($to, 'UTC')->subDays(29)->toDateString();
        if ($from > $to || CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 365) {
            throw ValidationException::withMessages(['from' => 'Choose a date range of at most 366 days, with the start on or before the end.']);
        }

        return ['from' => $from, 'to' => $to];
    }

    /** @return array<string, mixed> */
    private function decode(?\stdClass $row): array
    {
        $result = [];
        foreach (['summary', 'breakdown', 'trend', 'agents'] as $key) {
            $result[$key] = json_decode($row->{$key.'_json'} ?? '[]', true, flags: JSON_THROW_ON_ERROR);
        }
        usort($result['trend'], fn (array $a, array $b) => strcmp($a['date'], $b['date']));
        usort($result['agents'], fn (array $a, array $b) => $b['active'] <=> $a['active'] ?: strcmp($a['name'], $b['name']));
        usort($result['breakdown'], fn (array $a, array $b) => $b['total'] <=> $a['total'] ?: strcmp($a['label'], $b['label']));

        return $result;
    }

    public function index(Request $request, TicketStore $store): JsonResponse
    {
        $period = $this->period($request);
        $rows = $store->ready() ? $store->call('TicketAnalytics', [$request->user()->id, $period['from'], $period['to']]) : [];

        return $this->ticketData([
            ...$this->decode($rows[0] ?? null), 'filters' => $period,
            'databaseReady' => $store->ready(),
            'isStaff' => in_array('tickets.update', app(TicketPermissions::class)->forUser($request->user()), true),
            'canExport' => in_array('reports.export', app(TicketPermissions::class)->forUser($request->user()), true),
            'generatedAt' => now()->utc()->toIso8601String(),
        ]);
    }

    public function export(Request $request, TicketStore $store): StreamedResponse
    {
        $period = $this->period($request);
        $rows = $store->call('TicketAnalyticsExport', [$request->user()->id, $period['from'], $period['to']]);
        $report = $this->decode($rows[0] ?? null);
        $sheets = [
            ['name' => 'Summary', 'headers' => ['Metric', 'Tickets'], 'widths' => [38, 34],
                'note' => 'Current state of tickets created in the selected period. SLA counts cover active tickets using their current deadline.',
                'rows' => array_map(fn ($key, $value) => [ucwords(str_replace('_', ' ', (string) $key)), (int) $value], array_keys($report['summary']), array_values($report['summary']))],
            ['name' => 'Breakdowns', 'headers' => ['Dimension', 'Label', 'Tickets'], 'widths' => [22, 36, 18],
                'note' => 'Status, priority, and category each count a ticket once, including shared tickets.',
                'rows' => array_map(fn ($row) => [(string) $row['dimension'], (string) $row['label'], (int) $row['total']], array_values($report['breakdown']))],
            ['name' => 'Daily trend', 'headers' => ['Created date (UTC)', 'Tickets'], 'widths' => [36, 36],
                'note' => 'Ticket creation volume for each day in the selected period, including days with no tickets.', 'rows' => []],
        ];
        $daily = array_column($report['trend'], 'total', 'date');
        for ($day = CarbonImmutable::parse($period['from'], 'UTC'); $day->toDateString() <= $period['to']; $day = $day->addDay()) {
            $sheets[2]['rows'][] = [$day, (int) ($daily[$day->toDateString()] ?? 0)];
        }
        if (in_array('tickets.update', app(TicketPermissions::class)->forUser($request->user()), true)) {
            $sheets[] = ['name' => 'Agent performance',
                'headers' => ['Agent ID', 'Agent', 'Assigned', 'Active', 'Completed assignments', 'Overdue', 'Public replies', 'Tickets replied', 'First responses', 'Avg first response (min)'],
                'widths' => [14, 32, 16, 16, 23, 16, 18, 18, 18, 24],
                'note' => 'Shared tickets count for each current assignee. Completed assignments are not individual resolution credit. Replies cover the full history of selected tickets, excluding private notes and requester replies. First response is credited to its author. Blank average means no first responses. Current staff roles apply.',
                'rows' => array_map(fn ($row) => [
                    (int) $row['id'], (string) $row['name'], (int) $row['assigned'], (int) $row['active'], (int) $row['completed'],
                    (int) $row['overdue'], (int) $row['public_replies'], (int) $row['tickets_replied'], (int) $row['first_replies'],
                    $row['first_reply_minutes'] === null ? null : (float) $row['first_reply_minutes'],
                ], array_values($report['agents']))];
        }

        return app(ExcelReport::class)->download(
            "ticket-report-{$period['from']}-{$period['to']}.xlsx",
            "Created {$period['from']} through {$period['to']} (UTC). Generated ".now()->utc()->format('Y-m-d H:i').' UTC.',
            $sheets,
        );
    }

    /** @return array{summary: array<string, int|float>, by_status: list<array{label: string, asset_records: int, units: int, recorded_value: float}>, by_type: list<array{label: string, asset_records: int, units: int, recorded_value: float}>, by_division: list<array{label: string, asset_records: int, units: int, recorded_value: float}>} */
    private function inventorySnapshot(array $rows): array
    {
        $summary = [
            'asset_records' => count($rows),
            'tracked_units' => 0,
            'deployed_units' => 0,
            'available_units' => 0,
            'damaged_assets' => 0,
            'repair_assets' => 0,
            'out_of_stock_assets' => 0,
            'retired_assets' => 0,
            'recorded_value' => 0.0,
            'assets_without_cost' => 0,
        ];
        $groups = ['by_status' => [], 'by_type' => [], 'by_division' => []];
        $addGroup = static function (array &$target, string $label, int $quantity, ?float $value): void {
            $target[$label] ??= ['label' => $label, 'asset_records' => 0, 'units' => 0, 'recorded_value' => 0.0];
            $target[$label]['asset_records']++;
            $target[$label]['units'] += $quantity;
            if ($value !== null) {
                $target[$label]['recorded_value'] += $value;
            }
        };

        foreach ($rows as $row) {
            $quantity = (int) ($row->quantity ?? 1);
            $status = (string) ($row->status ?? 'unknown');
            $type = (string) ($row->type ?? 'other');
            $division = trim((string) ($row->division ?? '')) ?: 'Unassigned';
            $unitCost = $row->unit_cost ?? null;
            $value = $unitCost === null ? null : $quantity * (float) $unitCost;

            $summary['tracked_units'] += $quantity;
            if ($status === 'deployed') {
                $summary['deployed_units'] += $quantity;
            }
            if (in_array($status, ['available', 'returned'], true)) {
                $summary['available_units'] += $quantity;
            }
            if ($status === 'damaged') {
                $summary['damaged_assets']++;
            }
            if ($status === 'repair') {
                $summary['repair_assets']++;
            }
            if ($status === 'out_of_stock') {
                $summary['out_of_stock_assets']++;
            }
            if ($status === 'retired') {
                $summary['retired_assets']++;
            }
            if ($value === null) {
                $summary['assets_without_cost']++;
            } else {
                $summary['recorded_value'] += $value;
            }

            $addGroup($groups['by_status'], $status, $quantity, $value);
            $addGroup($groups['by_type'], $type, $quantity, $value);
            $addGroup($groups['by_division'], $division, $quantity, $value);
        }

        foreach ($groups as &$items) {
            $items = array_values($items);
            usort($items, fn (array $a, array $b) => $b['asset_records'] <=> $a['asset_records'] ?: strcmp($a['label'], $b['label']));
        }
        unset($items);

        return ['summary' => $summary, ...$groups];
    }

    /** @return list<\stdClass> */
    private function allInventoryRows(Request $request, TicketStore $store, string $procedure): array
    {
        $rows = $store->call($procedure, [$request->user()->id, '', '', '', ...($procedure === 'AssetExport' ? [] : [0])]);
        $total = (int) ($rows[0]->total_count ?? count($rows));
        abort_if($total > 10000 || count($rows) > 10000, 422, 'The inventory report is limited to 10,000 assets. Narrow the inventory before exporting.');

        return array_values($rows);
    }

    public function inventory(Request $request, TicketStore $store): JsonResponse
    {
        $permissions = app(TicketPermissions::class)->forUser($request->user());
        abort_unless(in_array('inventory.view', $permissions, true), 403, 'Inventory access is required to view this report.');
        $databaseReady = $store->ready();
        $rows = $databaseReady ? $this->allInventoryRows($request, $store, 'AssetList') : [];

        return $this->ticketData([
            ...$this->inventorySnapshot($rows),
            'canExportInventory' => in_array('reports.export', $permissions, true) && in_array('inventory.export', $permissions, true),
            'generatedAt' => now()->utc()->toIso8601String(),
            'databaseReady' => $databaseReady,
        ]);
    }

    public function inventoryExport(Request $request, TicketStore $store): StreamedResponse
    {
        $permissions = app(TicketPermissions::class)->forUser($request->user());
        abort_unless(in_array('inventory.view', $permissions, true) && in_array('inventory.export', $permissions, true), 403, 'Inventory export permission is required.');
        $rows = $this->allInventoryRows($request, $store, 'AssetExport');
        $report = $this->inventorySnapshot($rows);
        $groupRows = static fn (array $groups): array => array_map(fn (array $row) => [
            (string) $row['label'], (int) $row['asset_records'], (int) $row['units'], (float) $row['recorded_value'],
        ], $groups);
        $assets = array_map(fn ($row) => [
            (string) $row->asset_tag,
            (string) ($row->serial_number ?? ''),
            (string) $row->name,
            (string) $row->type,
            (string) ($row->brand ?? ''),
            (string) ($row->model ?? ''),
            (string) ($row->specifications ?? ''),
            (int) ($row->quantity ?? 1),
            $row->unit_cost !== null ? (float) $row->unit_cost : null,
            $row->unit_cost !== null ? (int) ($row->quantity ?? 1) * (float) $row->unit_cost : null,
            (string) $row->status,
            (string) ($row->holder_name ?? ''),
            (string) ($row->employee_code ?? ''),
            (string) ($row->department ?? ''),
            (string) ($row->division ?? ''),
            (string) ($row->location ?? ''),
            (string) ($row->accessories ?? ''),
            $row->deployed_at ? CarbonImmutable::parse($row->deployed_at, 'UTC') : null,
            $row->purchase_date ? CarbonImmutable::parse($row->purchase_date, 'UTC') : null,
            $row->warranty_until ? CarbonImmutable::parse($row->warranty_until, 'UTC') : null,
        ], $rows);
        $sheets = [
            ['name' => 'Summary', 'headers' => ['Metric', 'Value'], 'widths' => [36, 24],
                'note' => 'Current inventory snapshot. Recorded value includes only assets with a saved unit cost; it is quantity multiplied by unit cost.',
                'rows' => [
                    ['Asset records', (int) $report['summary']['asset_records']],
                    ['Tracked units', (int) $report['summary']['tracked_units']],
                    ['Deployed units', (int) $report['summary']['deployed_units']],
                    ['Available or returned units', (int) $report['summary']['available_units']],
                    ['Damaged asset records', (int) $report['summary']['damaged_assets']],
                    ['Assets in repair', (int) $report['summary']['repair_assets']],
                    ['Out-of-stock consumables', (int) $report['summary']['out_of_stock_assets']],
                    ['Retired asset records', (int) $report['summary']['retired_assets']],
                    ['Recorded value (PHP)', (float) $report['summary']['recorded_value']],
                    ['Assets without unit cost', (int) $report['summary']['assets_without_cost']],
                ]],
            ['name' => 'By status', 'headers' => ['Status', 'Asset records', 'Units', 'Recorded value (PHP)'], 'widths' => [22, 20, 16, 25],
                'note' => 'Current asset counts, quantity, and recorded value grouped by lifecycle status.', 'rows' => $groupRows($report['by_status'])],
            ['name' => 'By type', 'headers' => ['Asset type', 'Asset records', 'Units', 'Recorded value (PHP)'], 'widths' => [22, 20, 16, 25],
                'note' => 'Current asset counts, quantity, and recorded value grouped by asset type.', 'rows' => $groupRows($report['by_type'])],
            ['name' => 'By division', 'headers' => ['Division', 'Asset records', 'Units', 'Recorded value (PHP)'], 'widths' => [32, 20, 16, 25],
                'note' => 'Assets without a division are grouped under Unassigned.', 'rows' => $groupRows($report['by_division'])],
            ['name' => 'Asset register',
                'headers' => ['Asset tag', 'Serial number', 'Asset', 'Type', 'Brand', 'Model', 'Specifications', 'Quantity', 'Unit cost (PHP)', 'Total value (PHP)', 'Status', 'Employee', 'Employee ID', 'Department', 'Division', 'Location', 'Accessories', 'Deployed (UTC)', 'Purchase date', 'Warranty until'],
                'widths' => [22, 24, 32, 18, 20, 24, 50, 12, 20, 22, 18, 30, 20, 24, 24, 28, 40, 24, 20, 20],
                'note' => 'Current asset register. Total value is quantity multiplied by recorded unit cost. Blank cost means no purchase cost was recorded.',
                'rows' => $assets],
        ];

        return app(ExcelReport::class)->download(
            'inventory-report-'.now()->utc()->format('Y-m-d').'.xlsx',
            'Current inventory snapshot. Generated '.now()->utc()->format('Y-m-d H:i').' UTC.',
            $sheets,
        );
    }
}
