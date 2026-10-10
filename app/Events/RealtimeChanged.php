<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class RealtimeChanged implements ShouldBroadcastNow
{
    public function __construct(private string $channel, private string $eventName) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->channel)];
    }

    public function broadcastAs(): string
    {
        return $this->eventName;
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        // Signal only: authorized HTTP endpoints return the current data.
        // Existing sockets never receive message bodies after role revocation.
        return [];
    }
}
