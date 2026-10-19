<?php

namespace App\Http\Controllers\Api;

use App\Enums\Lane;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\Ticket;
use App\Services\TicketIssuer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class KioskController extends Controller
{
    /** Active services + people waiting per lane + estimated minutes per patient. */
    public function services()
    {
        $avg = $this->averageMinutes();
        $waiting = Ticket::today()
            ->where('status', TicketStatus::Waiting)
            ->selectRaw('service_id, lane, COUNT(*) as total')
            ->groupBy('service_id', 'lane')
            ->get()
            ->groupBy('service_id');

        return Service::active()->get()->map(fn (Service $s) => [
            'id' => $s->id,
            'code' => $s->code,
            'name' => $s->name,
            'logo' => $s->logo_path ? asset($s->logo_path) : null,
            'waiting' => collect(Lane::cases())->mapWithKeys(fn (Lane $l) => [
                $l->value => (int) optional($waiting->get($s->id)?->firstWhere('lane', $l->value))->total,
            ]),
            'minutes_per_patient' => $avg[$s->id] ?? self::DEFAULT_MINUTES,
            'windows_open' => $s->counters()->where('is_active', true)->count(),
        ]);
    }

    private const DEFAULT_MINUTES = 6;

    /**
     * Average minutes from call to done per service today (last 20 patients),
     * clamped to 2-30 min so one odd case does not distort the estimate.
     */
    private function averageMinutes(): array
    {
        return Ticket::today()
            ->where('status', TicketStatus::Done)
            ->whereNotNull('called_at')
            ->whereNotNull('finished_at')
            ->orderByDesc('finished_at')
            ->limit(200)
            ->get(['service_id', 'called_at', 'finished_at'])
            ->groupBy('service_id')
            ->map(fn ($rows) => (int) round(min(30, max(2,
                $rows->take(20)->avg(fn ($t) => $t->called_at->diffInSeconds($t->finished_at) / 60)
            ))))
            ->all();
    }

    public function issue(Request $request, TicketIssuer $issuer)
    {
        $data = $request->validate([
            'service_id' => ['required', Rule::exists('services', 'id')->where('is_active', true)],
            'lane' => ['required', Rule::enum(Lane::class)],
        ]);

        $ticket = $issuer->issue(Service::findOrFail($data['service_id']), Lane::from($data['lane']));

        $avg = $this->averageMinutes()[$ticket->service_id] ?? self::DEFAULT_MINUTES;
        $windows = max(1, $ticket->service->counters()->where('is_active', true)->count());

        $ahead = Ticket::today()
            ->where('service_id', $ticket->service_id)
            ->where('status', TicketStatus::Waiting)
            ->where('id', '<', $ticket->id)
            ->count();

        return response()->json([
            'ticket' => [
                'id' => $ticket->id,
                'code' => $ticket->code,
                'lane' => $ticket->lane->value,
                'lane_label' => $ticket->lane->label(),
                'service' => $ticket->service->only(['code', 'name']),
                'issued_at' => $ticket->created_at->timezone(config('queue_system.timezone'))->format('M d, Y h:i A'),
                'ahead' => $ahead,
                'estimated_minutes' => (int) ceil($ahead * $avg / $windows),
            ],
            'hospital' => config('queue_system.hospital'),
        ], 201);
    }
}
