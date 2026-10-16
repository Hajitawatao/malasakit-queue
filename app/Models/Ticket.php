<?php

namespace App\Models;

use App\Enums\Lane;
use App\Enums\TicketStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Ticket extends Model
{
    protected $fillable = [
        'service_id', 'lane', 'number', 'code', 'queue_date', 'status',
        'counter_id', 'served_by', 'recall_count',
        'called_at', 'serving_at', 'finished_at',
    ];

    protected $casts = [
        'lane' => Lane::class,
        'status' => TicketStatus::class,
        'queue_date' => 'date',
        'called_at' => 'datetime',
        'serving_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function counter(): BelongsTo
    {
        return $this->belongsTo(Counter::class);
    }

    public function scopeToday($q)
    {
        return $q->whereDate('queue_date', today(config('queue_system.timezone')));
    }

    public function toBroadcast(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'lane' => $this->lane->value,
            'status' => $this->status->value,
            'service' => $this->service?->only(['id', 'code', 'name']),
            'counter' => $this->counter?->only(['id', 'name']),
            'called_at' => $this->called_at?->toIso8601String(),
        ];
    }
}
