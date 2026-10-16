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
    /** Listahan ng 4 na service + bilang ng naghihintay kada lane (para sa modal). */
    public function services()
    {
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
        ]);
    }

    public function issue(Request $request, TicketIssuer $issuer)
    {
        $data = $request->validate([
            'service_id' => ['required', Rule::exists('services', 'id')->where('is_active', true)],
            'lane' => ['required', Rule::enum(Lane::class)],
        ]);

        $ticket = $issuer->issue(Service::findOrFail($data['service_id']), Lane::from($data['lane']));

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
            ],
            'hospital' => config('queue_system.hospital'),
        ], 201);
    }
}
