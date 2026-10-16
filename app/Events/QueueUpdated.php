<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Magaan na "may nagbago" signal. Ang mga screen ang magre-refetch
 * ng snapshot, kaya hindi nagkakaiba-iba ang state kahit may na-miss na event.
 */
class QueueUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public int $serviceId) {}

    public function broadcastOn(): array
    {
        return [new Channel('queue.display'), new Channel("queue.service.{$this->serviceId}")];
    }

    public function broadcastAs(): string
    {
        return 'queue.updated';
    }

    public function broadcastWith(): array
    {
        return ['service_id' => $this->serviceId];
    }
}
