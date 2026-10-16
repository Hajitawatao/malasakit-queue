<?php

namespace App\Enums;

enum Lane: string
{
    case Fast = 'fast';          // Senior, PWD, buntis (RA 9994 / RA 10754)
    case Priority = 'priority';  // Referral, emergency, endorsed
    case Regular = 'regular';

    public function prefix(): string
    {
        return match ($this) {
            self::Fast => 'F',
            self::Priority => 'P',
            self::Regular => 'R',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Fast => 'Fast Lane',
            self::Priority => 'Priority',
            self::Regular => 'Regular',
        };
    }

    /** Ilang beses tatawag mula sa lane na ito bago bigyan ng turn ang regular. */
    public function weight(): int
    {
        return (int) config("queue_system.weights.{$this->value}", 1);
    }
}
