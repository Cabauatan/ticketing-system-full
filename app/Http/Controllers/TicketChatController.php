<?php

namespace App\Http\Controllers;

use App\Services\TicketStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketChatController extends Controller
{
    public function index(Request $request, int $ticket, TicketStore $store): JsonResponse
    {
        $data = $request->validate(['after' => ['nullable', 'integer', 'min:0']]);
        $rows = $store->call('TicketChatRead', [$request->user()->id, $ticket, $data['after'] ?? 0]);
        $messages = json_decode($rows[0]->messages_json, true, flags: JSON_THROW_ON_ERROR);
        usort($messages, fn (array $a, array $b) => $a['id'] <=> $b['id']);

        return response()->json(['data' => [
            'messages' => $messages, 'unread' => (int) $rows[0]->unread,
            'typing' => json_decode($rows[0]->typing_json, true, flags: JSON_THROW_ON_ERROR),
            'has_more' => count($messages) === 100,
        ]]);
    }

    public function presence(Request $request, int $ticket, TicketStore $store): JsonResponse
    {
        $data = $request->validate([
            'typing' => ['required', 'boolean'], 'internal' => ['required', 'boolean'],
            'seen' => ['required', 'integer', 'min:0'],
        ]);
        $store->call('TicketChatPresence', [$request->user()->id, $ticket, (int) $data['typing'], (int) $data['internal'], $data['seen']]);

        return response()->json(['message' => 'Updated.']);
    }
}
