<?php

namespace App\Services;

use App\Enums\Lane;
use App\Enums\TicketStatus;
use App\Events\QueueUpdated;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\TicketSequence;
use Illuminate\Support\Facades\DB;

/**
 * Gumagawa ng bagong ticket galing sa kiosk.
 * Ang numero ay nagre-reset kada araw, hiwalay kada service at lane:
 *   DSWD  R001, R002...  F001, F002...  P001...
 *   PAGCOR R001...       (sariling bilang)
 */
class TicketIssuer
{
    public function issue(Service $service, Lane $lane): Ticket
    {
        $today = today(config('queue_system.timezone'))->toDateString();

        $ticket = DB::transaction(function () use ($service, $lane, $today) {
            // Siguraduhing may row, saka i-lock (FOR UPDATE) para atomic ang increment.
            TicketSequence::firstOrCreate(
                ['service_id' => $service->id, 'lane' => $lane->value, 'queue_date' => $today],
                ['last_number' => 0]
            );

            $seq = TicketSequence::where([
                'service_id' => $service->id,
                'lane' => $lane->value,
                'queue_date' => $today,
            ])->lockForUpdate()->first();

            $seq->increment('last_number');
            $number = $seq->last_number;

            return Ticket::create([
                'service_id' => $service->id,
                'lane' => $lane,
                'number' => $number,
                'code' => $lane->prefix() . str_pad((string) $number, config('queue_system.number_padding'), '0', STR_PAD_LEFT),
                'queue_date' => $today,
                'status' => TicketStatus::Waiting,
            ]);
        });

        QueueUpdated::dispatch($service->id);

        return $ticket->load('service');
    }
}
