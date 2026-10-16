<?php

namespace App\Http\Controllers\Api;

use App\Enums\Lane;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\Counter;
use App\Models\Ticket;
use App\Services\QueueCaller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Ginagamit ng PC station ng bawat bintana (DSWD, PAGCOR, atbp.).
 * Ang counter ay galing sa naka-login na user, hindi sa request,
 * para hindi makatawag ang DSWD staff sa pila ng PAGCOR.
 */
class CounterController extends Controller
{
    public function __construct(private QueueCaller $caller) {}

    public function state(Request $request)
    {
        $counter = $this->counter($request)->load('service:id,code,name');

        $current = Ticket::where('counter_id', $counter->id)
            ->whereIn('status', TicketStatus::active())
            ->latest('called_at')
            ->first();

        $waiting = Ticket::today()
            ->where('service_id', $counter->service_id)
            ->where('status', TicketStatus::Waiting)
            ->selectRaw('lane, COUNT(*) as total')
            ->groupBy('lane')
            ->pluck('total', 'lane');

        $servedToday = Ticket::today()
            ->where('counter_id', $counter->id)
            ->where('status', TicketStatus::Done)
            ->count();

        return [
            'counter' => $counter->only(['id', 'name']) + ['service' => $counter->service],
            'current' => $current ? [
                'id' => $current->id,
                'code' => $current->code,
                'lane' => $current->lane->value,
                'status' => $current->status->value,
                'recall_count' => $current->recall_count,
                'called_at' => $current->called_at?->toIso8601String(),
            ] : null,
            'waiting' => collect(Lane::cases())->mapWithKeys(fn ($l) => [$l->value => (int) ($waiting[$l->value] ?? 0)]),
            'served_today' => $servedToday,
            'max_recalls' => config('queue_system.max_recalls'),
        ];
    }

    public function callNext(Request $request)
    {
        $data = $request->validate(['lane' => ['nullable', Rule::enum(Lane::class)]]);

        $ticket = $this->caller->callNext(
            $this->counter($request),
            $request->user(),
            isset($data['lane']) ? Lane::from($data['lane']) : null,
        );

        if (! $ticket) {
            return response()->json(['message' => 'Walang naghihintay sa pila.'], 404);
        }

        return $this->state($request);
    }

    public function recall(Request $request, Ticket $ticket)
    {
        $this->caller->recall($ticket, $this->counter($request));
        return $this->state($request);
    }

    public function serve(Request $request, Ticket $ticket)
    {
        $this->caller->startServing($ticket, $this->counter($request));
        return $this->state($request);
    }

    public function done(Request $request, Ticket $ticket)
    {
        $this->caller->finish($ticket, $this->counter($request), TicketStatus::Done);
        return $this->state($request);
    }

    public function noShow(Request $request, Ticket $ticket)
    {
        $this->caller->finish($ticket, $this->counter($request), TicketStatus::NoShow);
        return $this->state($request);
    }

    private function counter(Request $request): Counter
    {
        $counter = $request->user()->counter;
        abort_unless($counter && $counter->is_active, 403, 'Walang naka-assign na counter sa account na ito.');
        return $counter;
    }
}
