<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsToTicketRequests;
use App\Services\ExcelReport;
use App\Services\TicketPermissions;
use App\Services\TicketStore;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class InventoryController extends Controller
{
    use RespondsToTicketRequests;

    private const TYPES = ['desktop', 'laptop', 'monitor', 'printer', 'network', 'peripheral', 'consumable', 'other'];

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', Rule::in(['available', 'out_of_stock', 'deployed', 'returned', 'damaged', 'replaced', 'repair', 'retired'])],
            'type' => ['nullable', Rule::in(self::TYPES)],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);
    }

    public function index(Request $request, TicketStore $store): JsonResponse
    {
        $filters = $this->filters($request);
        $page = (int) ($filters['page'] ?? 1);
        $rows = $store->ready() ? $store->call('AssetList', [$request->user()->id, $filters['search'] ?? '', $filters['status'] ?? '', $filters['type'] ?? '', $page]) : [];

        return $this->ticketData([
            'assets' => $rows, 'filters' => $filters, 'page' => $page, 'total' => $rows[0]->total_count ?? 0,
            'databaseReady' => $store->ready(),
        ]);
    }

    public function show(Request $request, int $asset, TicketStore $store): JsonResponse
    {
        $rows = $store->call('AssetShow', [$request->user()->id, $asset]);
        abort_if($rows === [], 404);
        $record = $rows[0];
        $record->history = json_decode($record->history_json ?? '[]', true, flags: JSON_THROW_ON_ERROR);
        $record->tickets = json_decode($record->tickets_json ?? '[]', true, flags: JSON_THROW_ON_ERROR);
        $record->proof_attachments = $store->call('AssetAttachmentList', [$request->user()->id, $asset]);
        usort($record->history, fn ($a, $b) => $b['id'] <=> $a['id']);
        unset($record->history_json, $record->tickets_json);

        return $this->ticketData(['asset' => $record]);
    }

    public function store(Request $request, TicketStore $store): Response
    {
        return $this->save($request, $store, null);
    }

    public function update(Request $request, int $asset, TicketStore $store): Response
    {
        return $this->save($request, $store, $asset);
    }

    private function save(Request $request, TicketStore $store, ?int $asset): Response
    {
        $data = $request->validate([
            'version' => [$asset === null ? 'nullable' : 'required', 'integer', 'min:1'],
            'asset_tag' => ['required', 'string', 'max:60'], 'name' => ['required', 'string', 'max:160'],
            'type' => ['required', Rule::in(self::TYPES)], 'serial_number' => ['nullable', 'string', 'max:120'],
            'brand' => ['nullable', 'string', 'max:80'], 'model' => ['nullable', 'string', 'max:120'],
            'quantity' => ['sometimes', 'integer', 'min:0', 'max:999999'],
            'unit_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'specifications' => ['nullable', 'string', 'max:10000'],
            'division' => ['nullable', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:160'],
            'accessories' => ['nullable', 'string', 'max:2000'],
            'purchase_date' => ['nullable', 'date_format:Y-m-d'],
            'warranty_until' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $data['quantity'] = (int) ($data['quantity'] ?? 1);
        if ($data['type'] !== 'consumable' && $data['quantity'] < 1) {
            throw ValidationException::withMessages(['quantity' => 'Regular assets must have at least one unit.']);
        }
        if ($data['type'] === 'consumable' && ! empty($data['serial_number'])) {
            throw ValidationException::withMessages(['serial_number' => 'Consumables are tracked by stock quantity, not by serial number.']);
        }
        if (! empty($data['serial_number']) && $data['quantity'] !== 1) {
            throw ValidationException::withMessages(['quantity' => 'Create a separate inventory record for each serialized unit.']);
        }
        if (! empty($data['purchase_date']) && ! empty($data['warranty_until']) && $data['warranty_until'] < $data['purchase_date']) {
            throw ValidationException::withMessages(['warranty_until' => 'Warranty cannot end before purchase.']);
        }
        $rows = $store->call('AssetSave', [$request->user()->id, $asset, $data['version'] ?? null, json_encode($data, JSON_THROW_ON_ERROR)]);
        $id = $rows[0]->id;

        return $request->routeIs('api.*') ? response()->json(['data' => ['id' => $id]], $asset === null ? 201 : 200) : to_route('inventory.show', ['asset' => $id]);
    }

    public function move(Request $request, int $asset, TicketStore $store): Response
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'action' => ['required', Rule::in(['deploy', 'transfer', 'return', 'damage', 'replace', 'repair', 'restore', 'retire'])],
            'holder_name' => ['required_if:action,deploy,transfer', 'nullable', 'string', 'max:160'],
            'employee_code' => ['nullable', 'string', 'max:80'],
            'department' => ['required_if:action,deploy,transfer', 'nullable', 'string', 'max:120'],
            'division' => ['nullable', 'string', 'max:120'],
            'location' => ['required', 'string', 'max:160'], 'accessories' => ['nullable', 'string', 'max:2000'],
            'note' => ['required', 'string', 'max:2000'],
        ]);
        $store->call('AssetMove', [$request->user()->id, $asset, $data['version'], $data['action'], json_encode($data, JSON_THROW_ON_ERROR)]);

        return $request->routeIs('api.*') ? response()->json(['message' => 'Movement recorded.']) : to_route('inventory.show', ['asset' => $asset]);
    }

    public function stock(Request $request, int $asset, TicketStore $store): JsonResponse
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'action' => ['required', Rule::in(['restock', 'issue'])],
            'quantity' => ['required', 'integer', 'min:1', 'max:999999'],
            'recipient' => ['required_if:action,issue', 'nullable', 'string', 'max:160'],
            'employee_code' => ['nullable', 'string', 'max:80'],
            'department' => ['nullable', 'string', 'max:120'],
            'division' => ['nullable', 'string', 'max:120'],
            'location' => ['nullable', 'string', 'max:160'],
            'ticket_id' => ['nullable', 'integer', 'min:1', 'exists:tickets,id'],
            'note' => ['required', 'string', 'max:2000'],
        ]);
        $store->call('AssetStockMove', [
            $request->user()->id, $asset, $data['version'], $data['action'], $data['quantity'], json_encode($data, JSON_THROW_ON_ERROR),
        ]);

        return response()->json(['message' => $data['action'] === 'issue' ? 'Consumable stock issued.' : 'Consumable stock replenished.']);
    }

    public function storeProof(Request $request, int $asset, TicketStore $store): JsonResponse
    {
        $store->call('AssetAttachmentList', [$request->user()->id, $asset]);
        $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png', 'extensions:pdf,jpg,jpeg,png'],
        ]);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);
        $filename = Str::limit(preg_replace('/[\x00-\x1F\x7F\\\\\/]/u', '_', $file->getClientOriginalName()) ?? 'proof', 200, '');
        $path = $file->storeAs("inventory/$asset", Str::uuid()->toString(), 'attachments');
        abort_if($path === false, 503, 'Proof file could not be saved. Please try again.');
        try {
            $rows = $store->call('AssetAttachmentAdd', [
                $request->user()->id, $asset, $filename, $path, $file->getMimeType(), $file->getSize(),
            ]);
        } catch (Throwable $exception) {
            Storage::disk('attachments')->delete($path);
            throw $exception;
        }

        return response()->json(['data' => ['id' => $rows[0]->id ?? null], 'message' => 'Proof uploaded.'], 201);
    }

    public function downloadProof(Request $request, int $asset, int $attachment, TicketStore $store): StreamedResponse
    {
        $rows = $store->call('AssetAttachmentGet', [$request->user()->id, $asset, $attachment]);
        abort_if($rows === [], 404);
        $record = $rows[0];
        $disk = Storage::disk('attachments');
        abort_unless($disk->exists($record->path), 404);

        return $disk->download($record->path, $record->filename, [
            'Content-Type' => $record->mime_type ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function link(Request $request, int $asset, TicketStore $store): Response
    {
        $permissions = app(TicketPermissions::class)->forUser($request->user());
        abort_unless(in_array('tickets.view', $permissions, true) && in_array('tickets.update', $permissions, true), 403);
        $data = $request->validate(['version' => ['required', 'integer', 'min:1'], 'ticket_id' => ['required', 'integer', 'min:1'], 'link' => ['required', 'boolean']]);
        $store->call('AssetTicketLink', [$request->user()->id, $asset, $data['version'], $data['ticket_id'], (int) $data['link']]);

        return $request->routeIs('api.*') ? response()->json(['message' => 'Ticket link saved.']) : to_route('inventory.show', ['asset' => $asset]);
    }

    public function ticketAssets(Request $request, int $ticket, TicketStore $store): JsonResponse
    {
        abort_unless(app(TicketPermissions::class)->allows($request->user(), 'tickets.view'), 403);

        return response()->json(['data' => $store->call('TicketAssets', [$request->user()->id, $ticket])]);
    }

    public function export(Request $request, TicketStore $store): StreamedResponse
    {
        $filters = $this->filters($request);
        $rows = $store->call('AssetExport', [$request->user()->id, $filters['search'] ?? '', $filters['status'] ?? '', $filters['type'] ?? '']);
        abort_if(count($rows) > 10000, 422, 'Narrow your filters to export at most 10,000 assets.');

        return app(ExcelReport::class)->download('inventory-'.now()->utc()->format('Y-m-d').'.xlsx', 'Generated '.now()->utc()->format('Y-m-d H:i').' UTC.', [[
            'name' => 'Inventory',
            'headers' => ['Asset tag', 'Serial number', 'Asset', 'Type', 'Brand', 'Model', 'Specifications', 'Quantity', 'Unit cost (PHP)', 'Total value (PHP)', 'Status', 'Employee', 'Employee ID', 'Department', 'Division', 'Location', 'Accessories', 'Deployed (UTC)', 'Purchase date', 'Warranty until'],
            'widths' => [22, 24, 32, 18, 20, 24, 50, 12, 20, 22, 18, 30, 20, 24, 24, 28, 40, 24, 20, 20],
            'note' => 'Current asset inventory. Filters: status '.($filters['status'] ?? 'all').', type '.($filters['type'] ?? 'all').', search '.($filters['search'] ?? '(none)').'. Total value is quantity multiplied by unit cost.',
            'rows' => array_map(fn ($row) => [
                (string) $row->asset_tag, (string) ($row->serial_number ?? ''), (string) $row->name, (string) $row->type,
                (string) ($row->brand ?? ''), (string) ($row->model ?? ''), (string) ($row->specifications ?? ''), (int) $row->quantity,
                $row->unit_cost !== null ? (float) $row->unit_cost : null, $row->unit_cost !== null ? (int) $row->quantity * (float) $row->unit_cost : null, (string) $row->status,
                (string) ($row->holder_name ?? ''), (string) ($row->employee_code ?? ''), (string) ($row->department ?? ''), (string) ($row->division ?? ''), (string) ($row->location ?? ''), (string) ($row->accessories ?? ''),
                $row->deployed_at ? CarbonImmutable::parse($row->deployed_at, 'UTC') : null,
                $row->purchase_date ? CarbonImmutable::parse($row->purchase_date, 'UTC') : null,
                $row->warranty_until ? CarbonImmutable::parse($row->warranty_until, 'UTC') : null,
            ], array_values($rows)),
        ]]);
    }
}
