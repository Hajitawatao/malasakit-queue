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
 * Used by the PC at each window (MSS-DOH, DSWD, PhilHealth).
 * The window comes from the logged-in user, not from the request,
 * so DSWD staff can never call PhilHealth's queue.
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

        // Average minutes from call to done at this window today
        $avg = Ticket::today()
            ->where('counter_id', $counter->id)
            ->where('status', TicketStatus::Done)
            ->whereNotNull('called_at')->whereNotNull('finished_at')
            ->get(['called_at', 'finished_at'])
            ->avg(fn ($t) => $t->called_at->diffInSeconds($t->finished_at));

        $queue = Ticket::today()
            ->where('service_id', $counter->service_id)
            ->where('status', TicketStatus::Waiting)
            ->orderByRaw("FIELD(lane, 'fast', 'priority', 'regular')")
            ->orderBy('id')
            ->limit(12)
            ->get(['id', 'code', 'lane', 'created_at']);

        $out = Ticket::today()
            ->where('service_id', $counter->service_id)
            ->where('status', TicketStatus::Out)
            ->with('counter:id,name')
            ->orderByDesc('finished_at')
            ->get(['id', 'code', 'lane', 'counter_id', 'finished_at']);

        return [
            'counter' => $counter->only(['id', 'name', 'spoken_name']) + ['service' => $counter->service],
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
            'avg_minutes' => $avg ? round($avg / 60, 1) : null,
            'queue' => $queue->map(fn ($t) => [
                'id' => $t->id, 'code' => $t->code, 'lane' => $t->lane->value,
                'issued_at' => $t->created_at?->toIso8601String(),
            ]),
            'out' => $out->map(fn ($t) => [
                'id' => $t->id, 'code' => $t->code, 'lane' => $t->lane->value,
                'window' => $t->counter?->name,
                'out_at' => $t->finished_at?->toIso8601String(),
            ]),
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
            return response()->json(['message' => 'No one is waiting in this lane.'], 404);
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

    /** Patient not at the window when called -> "Out when called" list. */
    public function out(Request $request, Ticket $ticket)
    {
        $this->caller->markOut($ticket, $this->counter($request));
        return $this->state($request);
    }

    /** Patient came back -> call the same number again to this window. */
    public function callBack(Request $request, Ticket $ticket)
    {
        $this->caller->callBack($ticket, $this->counter($request), $request->user());
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
        abort_unless($counter && $counter->is_active, 403, 'This account has no window assigned.');
        return $counter;
    }
}
