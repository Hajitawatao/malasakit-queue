<?php

namespace App\Services;

use App\Enums\Lane;
use App\Enums\TicketStatus;
use App\Events\QueueUpdated;
use App\Events\TicketCalled;
use App\Models\Counter;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lahat ng aksyon ng counter staff: Call Next, Recall, Serve, Done, No-show.
 */
class QueueCaller
{
    /**
     * Tawagin ang susunod na pasyente.
     * - Kapag may $lane, sa lane na iyon lang kukuha (hal. "Fast Lane Call Next" button).
     * - Kapag wala, weighted round-robin (default 2 fast : 1 priority : 1 regular)
     *   para hindi maipit ang regular buong araw.
     */
    public function callNext(Counter $counter, User $user, ?Lane $lane = null): ?Ticket
    {
        $serviceId = $counter->service_id;

        $ticket = DB::transaction(function () use ($counter, $user, $lane, $serviceId) {
            $lanesToTry = $lane ? [$lane] : $this->rotationOrder($serviceId);

            foreach ($lanesToTry as $tryLane) {
                // FOR UPDATE: if two windows press Call Next at the same time,
                // they can never get the same ticket.
                $next = Ticket::today()
                    ->where('service_id', $serviceId)
                    ->where('status', TicketStatus::Waiting)
                    ->where('lane', $tryLane->value)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                if (! $next) {
                    continue;
                }

                // Only finish the current patient once we know someone is next.
                // (Before: pressing Call Next on an empty queue ended the current patient.)
                Ticket::where('counter_id', $counter->id)
                    ->whereIn('status', TicketStatus::active())
                    ->update(['status' => TicketStatus::Done, 'finished_at' => now()]);

                $next->update([
                    'status' => TicketStatus::Called,
                    'counter_id' => $counter->id,
                    'served_by' => $user->id,
                    'called_at' => now(),
                ]);
                if (! $lane) {
                    $this->advanceRotation($serviceId, $tryLane);
                }

                return $next;
            }

            return null;
        });

        if (! $ticket) {
            return null;
        }

        $ticket->load('service', 'counter');
        QueueUpdated::dispatch($serviceId);
        TicketCalled::dispatch($ticket);

        return $ticket;
    }

    public function recall(Ticket $ticket, Counter $counter): Ticket
    {
        $this->assertOwnedBy($ticket, $counter);

        if ($ticket->recall_count >= config('queue_system.max_recalls')) {
            throw ValidationException::withMessages([
                'ticket' => 'Maximum recalls reached. Mark this patient as No-show.',
            ]);
        }

        $ticket->load('service', 'counter');
        $ticket->increment('recall_count');
        $ticket->update(['called_at' => now()]);

        TicketCalled::dispatch($ticket, isRecall: true);

        return $ticket;
    }

    public function startServing(Ticket $ticket, Counter $counter): Ticket
    {
        $this->assertOwnedBy($ticket, $counter);
        $ticket->update(['status' => TicketStatus::Serving, 'serving_at' => now()]);
        QueueUpdated::dispatch($ticket->service_id);

        return $ticket;
    }

    public function finish(Ticket $ticket, Counter $counter, TicketStatus $status = TicketStatus::Done): Ticket
    {
        $this->assertOwnedBy($ticket, $counter);
        $ticket->update(['status' => $status, 'finished_at' => now()]);
        QueueUpdated::dispatch($ticket->service_id);

        return $ticket;
    }

    // ---- Weighted round-robin -------------------------------------------

    /** Halimbawa ng cycle sa weights 2/1/1: [fast, fast, priority, regular] */
    private function cycle(): array
    {
        $cycle = [];
        foreach (Lane::cases() as $lane) {
            for ($i = 0; $i < $lane->weight(); $i++) {
                $cycle[] = $lane;
            }
        }
        return $cycle;
    }

    /** Ayos ng lanes na susubukan, simula sa kasalukuyang posisyon ng cycle. */
    private function rotationOrder(int $serviceId): array
    {
        $cycle = $this->cycle();
        $pos = (int) Cache::get($this->rotationKey($serviceId), 0) % count($cycle);

        $ordered = array_merge(array_slice($cycle, $pos), array_slice($cycle, 0, $pos));

        // Alisin ang duplicate pero panatilihin ang ayos
        // (hindi array_unique: hindi nasosort nang tama ang enum objects)
        $unique = [];
        foreach ($ordered as $lane) {
            $unique[$lane->value] ??= $lane;
        }
        return array_values($unique);
    }

    private function advanceRotation(int $serviceId, Lane $served): void
    {
        $cycle = $this->cycle();
        $key = $this->rotationKey($serviceId);
        $pos = (int) Cache::get($key, 0) % count($cycle);

        // Hanapin ang susunod na slot ng lane na na-serve, saka lumipat sa kasunod niya
        for ($i = 0; $i < count($cycle); $i++) {
            $idx = ($pos + $i) % count($cycle);
            if ($cycle[$idx] === $served) {
                Cache::put($key, ($idx + 1) % count($cycle), now()->endOfDay());
                return;
            }
        }
    }

    private function rotationKey(int $serviceId): string
    {
        return "queue:rotation:{$serviceId}:" . today(config('queue_system.timezone'))->toDateString();
    }

    private function assertOwnedBy(Ticket $ticket, Counter $counter): void
    {
        if ($ticket->counter_id !== $counter->id) {
            abort(403, 'This ticket belongs to another window.');
        }
    }
}
