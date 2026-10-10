<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;

trait RespondsToTicketRequests
{
    /** @param array<string, mixed> $data */
    protected function ticketData(array $data): JsonResponse
    {
        return response()->json(['data' => $data]);
    }
}
