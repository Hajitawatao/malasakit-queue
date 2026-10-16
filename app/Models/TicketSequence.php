<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TicketSequence extends Model
{
    public $timestamps = false;

    protected $fillable = ['service_id', 'lane', 'queue_date', 'last_number'];
}
