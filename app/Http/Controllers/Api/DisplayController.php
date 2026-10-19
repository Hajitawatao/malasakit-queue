<?php

namespace App\Http\Controllers\Api;

use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\Ticket;
use App\Services\Announcer;
use Illuminate\Support\Facades\Cache;

class DisplayController extends Controller
{
    /**
     * Buong snapshot ng TV display. Tinatawag sa page load at tuwing may
     * QueueUpdated event (at bawat 15s bilang fallback kung naputol ang websocket).
     */
    public function snapshot(Announcer $announcer)
    {
        $active = Ticket::today()
            ->whereIn('status', TicketStatus::active())
            ->with('counter:id,name,spoken_name')
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
            ->with(['service:id,code,name,spoken_name', 'counter:id,name,spoken_name'])
            ->orderBy('called_at')
            ->get()
            ->map(fn (Ticket $t) => [
                'key' => $t->id . '@' . $t->called_at->getTimestamp(),
                'code' => $t->code,
                'lane' => $t->lane->value,
                'service' => ['code' => $t->service->code, 'name' => $t->service->name],
                'counter' => ['name' => $t->counter?->spoken_name ?? $t->counter?->name],
                'speech' => $text = $announcer->textFor($t),
                'audio' => $announcer->cachedAudio($text),
            ]);

        // Huling 6 na tinawag ngayong araw (para sa "Recent calls" sa TV)
        $recent = Ticket::today()
            ->whereNotNull('called_at')
            ->with(['service:id,code,name', 'counter:id,name,spoken_name'])
            ->orderByDesc('called_at')
            ->limit(6)
            ->get()
            ->map(fn (Ticket $t) => [
                'code' => $t->code,
                'lane' => $t->lane->value,
                'service' => $t->service->only(['code', 'name']),
                'counter' => $t->counter?->spoken_name ?? $t->counter?->name,
                'time' => $t->called_at->timezone(config('queue_system.timezone'))->format('g:i A'),
            ]);

        // Voice files are made by the voice worker. If it is not running, tell the TV to
        // use the browser voice right away instead of waiting for files that never come.
        $installed = $announcer->engine();
        $workerAlive = (int) Cache::get('tts:worker:heartbeat', 0) >= time() - 20;

        return [
            'calls' => $calls,
            'voice_engine' => $workerAlive ? $installed : 'browser',
            'voice_installed' => $installed,
            'voice_worker' => $workerAlive,
            'recent' => $recent,
            'services' => Service::active()->with(['counters' => fn ($q) => $q->where('is_active', true)->orderBy('id')])->get()->map(fn (Service $s) => [
                'id' => $s->id,
                'code' => $s->code,
                'name' => $s->name,
                'logo' => $s->logo_path ? asset($s->logo_path) : null,
                'now_serving' => ($active->get($s->id) ?? collect())->map(fn ($t) => [
                    'code' => $t->code,
                    'lane' => $t->lane->value,
                    'counter' => $t->counter?->spoken_name ?? $t->counter?->name,
                ])->values(),
                'windows' => $s->counters->map(function ($c) use ($active, $s) {
                    $t = ($active->get($s->id) ?? collect())->firstWhere('counter_id', $c->id);

                    return [
                        'id' => $c->id,
                        'name' => $c->spoken_name ?: $c->name,
                        'code' => $t?->code,
                        'lane' => $t?->lane->value,
                        'status' => $t?->status->value,
                    ];
                })->values(),
                'next' => ($waiting->get($s->id) ?? collect())->take(8)->pluck('code'),
                'waiting_total' => ($waiting->get($s->id) ?? collect())->count(),
            ]),
            'server_time' => now()->toIso8601String(),
        ];
    }

    /** Plays a sample announcement (TV "Test voice" button). */
    public function testVoice(Announcer $announcer)
    {
        $text = 'Now calling number R 1. Please proceed to D S W D, Window 1.';

        $audio = $announcer->audioFor($text);

        return ['speech' => $text, 'audio' => $audio, 'voice_engine' => $audio ? $announcer->engine() : 'browser'];
    }
}
