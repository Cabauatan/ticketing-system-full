<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsToTicketRequests;
use App\Services\ExcelReport;
use App\Services\TicketPermissions;
use App\Services\TicketStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DashboardController extends Controller
{
    use RespondsToTicketRequests;

    private function period(Request $request): int
    {
        $data = $request->validate(['days' => ['nullable', 'integer', Rule::in([0, 7, 30, 90])]]);

        return (int) ($data['days'] ?? 30);
    }

    public function index(Request $request, TicketStore $store): JsonResponse
    {
        $days = $this->period($request);

        return $this->ticketData([
            'report' => $store->ready() ? $store->call('TicketReport', [$request->user()->id, $days]) : [],
            'days' => $days,
            'databaseReady' => $store->ready(),
            'isStaff' => app(TicketPermissions::class)->allows($request->user(), 'tickets.update'),
            'generatedAt' => now()->utc()->toIso8601String(),
        ]);
    }

    public function export(Request $request, TicketStore $store): StreamedResponse
    {
        $days = $this->period($request);
        $rows = $store->call('TicketReportExport', [$request->user()->id, $days]);

        return app(ExcelReport::class)->download(
            'ticket-summary-'.now()->utc()->format('Y-m-d').'.xlsx',
            ($days === 0 ? 'All time' : "Tickets created within the last $days days").'. Generated '.now()->utc()->format('Y-m-d H:i').' UTC.',
            [['name' => 'Ticket summary', 'headers' => ['Dimension', 'Label', 'Tickets'], 'widths' => [24, 40, 18],
                'note' => 'Current ticket state. Shared active tickets count for each assigned agent in workload totals.',
                'rows' => array_map(fn ($row) => [(string) $row->dimension, (string) $row->label, (int) $row->total], array_values($rows))]],
        );
    }
}
