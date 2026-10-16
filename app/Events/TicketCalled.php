<?php

namespace App\Events;

use App\Models\Ticket;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Sinasalo ng TV display: ipapakita ang numero at babasahin ng boses.
 * ShouldBroadcastNow = walang queue worker na kailangan, deretso sa Reverb.
 */
class TicketCalled implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public Ticket $ticket, public bool $isRecall = false) {}

    public function broadcastOn(): Channel
    {
        return new Channel('queue.display');
    }

    public function broadcastAs(): string
    {
        return 'ticket.called';
    }

    public function broadcastWith(): array
    {
        return $this->ticket->toBroadcast() + ['is_recall' => $this->isRecall];
    }
}
