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

        // Mga kakatawag lang (huling 2 minuto). Ginagamit ng TV para malaman
        // kung ano ang babasahin ng boses; nag-iiba ang called_at kapag ni-recall.
        $calls = Ticket::today()
            ->whereIn('status', TicketStatus::active())
            ->where('called_at', '>=', now()->subMinutes(2))
            ->with(['service:id,name', 'counter:id,name'])
            ->orderBy('called_at')
            ->get()
            ->map(fn (Ticket $t) => [
                'key' => $t->id . '@' . $t->called_at->getTimestamp(),
                'code' => $t->code,
                'lane' => $t->lane->value,
                'service' => ['name' => $t->service->name],
                'counter' => ['name' => $t->counter?->name],
            ]);

        return [
            'calls' => $calls,
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
