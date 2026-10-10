<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondsToTicketRequests;
use App\Services\TicketStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TicketNotificationController extends Controller
{
    use RespondsToTicketRequests;

    public function index(Request $request, TicketStore $store): JsonResponse
    {
        $data = $request->validate(['page' => ['nullable', 'integer', 'min:1', 'max:100000']]);
        $page = (int) ($data['page'] ?? 1);
        $rows = $store->ready() ? $store->call('TicketNotifications', [$request->user()->id, $page]) : [];

        return $this->ticketData(['notifications' => $rows, 'page' => $page,
            'total' => $rows[0]->total_count ?? 0, 'unread' => $rows[0]->unread_count ?? 0]);
    }

    public function read(Request $request, int $notification, TicketStore $store): Response
    {
        $store->call('TicketNotificationRead', [$request->user()->id, $notification]);

        return $request->routeIs('api.*') ? response()->json(['message' => 'Saved.']) : back();
    }
}
