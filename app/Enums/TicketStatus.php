<?php

namespace App\Enums;

enum TicketStatus: string
{
    case Waiting = 'waiting';
    case Called = 'called';
    case Serving = 'serving';
    case Done = 'done';
    case NoShow = 'no_show';
    case Cancelled = 'cancelled';

    /** Mga status na "nasa counter" pa (hindi pa tapos). */
    public static function active(): array
    {
        return [self::Called->value, self::Serving->value];
    }
}
