<?php

namespace App\Http\Controllers\Api;

use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\Ticket;

class DisplayController extends Controller
{
    /**
     * Buong snapshot ng TV display. Tinatawag sa page load at tuwing may
     * QueueUpdated event (at bawat 15s bilang fallback kung naputol ang websocket).
     */
    public function snapshot()
    {
        $active = Ticket::today()
            ->whereIn('status', TicketStatus::active())
            ->with('counter:id,name')
            ->orderByDesc('called_at')
            ->get()
            ->groupBy('service_id');

        $waiting = Ticket::today()
            ->where('status', TicketStatus::Waiting)
            ->orderByRaw("FIELD(lane, 'fast', 'priority', 'regular')")
            ->orderBy('id')
            ->get(['id', 'service_id', 'code', 'lane'])
            ->groupBy('service_id');

        return [
            'services' => Service::active()->get()->map(fn (Service $s) => [
                'id' => $s->id,
                'code' => $s->code,
                'name' => $s->name,
                'now_serving' => ($active->get($s->id) ?? collect())->map(fn ($t) => [
                    'code' => $t->code,
                    'lane' => $t->lane->value,
                    'counter' => $t->counter?->name,
                ])->values(),
                'next' => ($waiting->get($s->id) ?? collect())->take(5)->pluck('code'),
                'waiting_total' => ($waiting->get($s->id) ?? collect())->count(),
            ]),
            'server_time' => now()->toIso8601String(),
        ];
    }
}
