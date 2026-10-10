<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsToTicketRequests;
use App\Services\TicketPermissions;
use App\Services\TicketStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class TicketController extends Controller
{
    use RespondsToTicketRequests;

    private const STATUSES = ['new', 'open', 'in_progress', 'pending', 'resolved', 'closed'];

    private const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    public function index(Request $request, TicketStore $store): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);
        $page = (int) ($filters['page'] ?? 1);
        $tickets = $store->ready() ? $store->call('TicketList', [$request->user()->id, $filters['search'] ?? '', $filters['status'] ?? '', $page]) : [];

        return $this->ticketData([
            'tickets' => $tickets, 'filters' => $filters, 'page' => $page,
            'total' => $tickets[0]->total_count ?? 0, 'databaseReady' => $store->ready(),
            'categories' => $store->ready() ? $store->call('TicketCategories', [$request->user()->id, 0]) : [],
        ]);
    }

    public function store(Request $request, TicketStore $store): Response
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:20000'],
            'category' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_-]*$/'],
            'priority' => ['sometimes', Rule::in(self::PRIORITIES)],
        ]);
        // Requesters always start at Low. Authorized staff may raise it afterward.
        $rows = $store->call('TicketCreate', [$request->user()->id, $data['subject'], $data['description'], $data['category'], 'low']);

        return $request->routeIs('api.*') ? response()->json(['data' => ['id' => $rows[0]->id]], 201) : to_route('tickets.show', ['ticket' => $rows[0]->id]);
    }

    public function show(Request $request, int $ticket, TicketStore $store): JsonResponse
    {
        $rows = $store->call('TicketShow', [$request->user()->id, $ticket]);
        abort_if($rows === [], 404);
        $record = $rows[0];
        $record->assignees = json_decode($record->assignees_json ?? '[]', true, flags: JSON_THROW_ON_ERROR);
        usort($record->assignees, fn (array $a, array $b) => $a['id'] <=> $b['id']);
        unset($record->assignees_json);
        $record->comments = json_decode($record->comments_json ?? '[]', true, flags: JSON_THROW_ON_ERROR);
        $record->events = json_decode($record->events_json ?? '[]', true, flags: JSON_THROW_ON_ERROR);
        // MySQL JSON_ARRAYAGG does not guarantee element ordering.
        usort($record->comments, fn (array $a, array $b) => $a['id'] <=> $b['id']);
        usort($record->events, fn (array $a, array $b) => $a['id'] <=> $b['id']);
        unset($record->comments_json, $record->events_json);

        $ticketPermissions = app(TicketPermissions::class);
        $canChangePriority = count(array_intersect($ticketPermissions->rolesForUser($request->user()), ['admin', 'superadmin', 'dispatcher'])) > 0;

        return $this->ticketData([
            'ticket' => $record,
            'attachments' => $store->call('TicketAttachmentList', [$request->user()->id, $ticket]),
            'canManage' => app(TicketPermissions::class)->allows($request->user(), 'tickets.update'),
            'canAssign' => app(TicketPermissions::class)->allows($request->user(), 'tickets.assign'),
            'canChangePriority' => $canChangePriority,
            'canInternal' => app(TicketPermissions::class)->allows($request->user(), 'tickets.internal'),
            'agents' => app(TicketPermissions::class)->allows($request->user(), 'tickets.assign')
                ? $store->call('TicketAgents', [$request->user()->id]) : [],
        ]);
    }

    public function update(Request $request, int $ticket, TicketStore $store): Response
    {
        $permissions = app(TicketPermissions::class);
        abort_unless($permissions->allows($request->user(), 'tickets.update'), 403);
        $canChangePriority = count(array_intersect($permissions->rolesForUser($request->user()), ['admin', 'superadmin', 'dispatcher'])) > 0;
        $hasAssignmentInput = $request->exists('assignee_id') || $request->exists('assignee_ids');
        abort_unless(! $hasAssignmentInput || $permissions->allows($request->user(), 'tickets.assign'), 403, 'Only dispatchers and administrators can assign tickets.');
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'status' => ['required', Rule::in(self::STATUSES)],
            'priority' => ['sometimes', 'required', Rule::in(self::PRIORITIES)],
            'assignee_id' => ['prohibits:assignee_ids', 'nullable', 'integer', 'min:1'],
            'assignee_ids' => ['sometimes', 'array', 'max:50'],
            'assignee_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);
        $current = null;
        if (! $canChangePriority || ! array_key_exists('priority', $data)
            || (! array_key_exists('assignee_ids', $data) && ! array_key_exists('assignee_id', $data))) {
            $current = $store->call('TicketShow', [$request->user()->id, $ticket]);
            abort_if($current === [], 404);
        }
        $currentPriority = (string) ($current[0]->priority ?? 'low');
        if (! $canChangePriority && array_key_exists('priority', $data)) {
            abort_unless($data['priority'] === $currentPriority, 403, 'Only admins, superadmins, and dispatchers can change ticket priority.');
        }
        $priority = $canChangePriority ? ($data['priority'] ?? $currentPriority) : $currentPriority;

        if (array_key_exists('assignee_ids', $data)) {
            $assigneeIds = array_map('intval', $data['assignee_ids']);
        } elseif (array_key_exists('assignee_id', $data)) {
            $assigneeIds = $data['assignee_id'] === null ? [] : [(int) $data['assignee_id']];
        } else {
            $current ??= $store->call('TicketShow', [$request->user()->id, $ticket]);
            abort_if($current === [], 404);
            $assignees = json_decode($current[0]->assignees_json ?? '[]', true, flags: JSON_THROW_ON_ERROR);
            $assigneeIds = array_map(static fn (array $assignee): int => (int) $assignee['id'], $assignees);
        }
        $store->call('TicketUpdateAgents', [$request->user()->id, $ticket, $data['version'], $data['status'], $priority, json_encode($assigneeIds, JSON_THROW_ON_ERROR)]);

        return $request->routeIs('api.*') ? response()->json(['message' => 'Saved.']) : to_route('tickets.show', ['ticket' => $ticket]);
    }

    public function reopen(Request $request, int $ticket, TicketStore $store): Response
    {
        $store->call('TicketReopen', [$request->user()->id, $ticket]);

        return $request->routeIs('api.*') ? response()->json(['message' => 'Saved.']) : to_route('tickets.show', ['ticket' => $ticket]);
    }

    public function comment(Request $request, int $ticket, TicketStore $store): Response
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:10000'], 'internal' => ['required', 'boolean']]);
        abort_if($data['internal'] && ! app(TicketPermissions::class)->allows($request->user(), 'tickets.internal'), 403);
        $store->call('TicketComment', [$request->user()->id, $ticket, $data['body'], (int) $data['internal']]);

        return $request->routeIs('api.*') ? response()->json(['message' => 'Saved.']) : to_route('tickets.show', ['ticket' => $ticket]);
    }
}
