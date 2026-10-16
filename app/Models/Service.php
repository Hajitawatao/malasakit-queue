<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    protected $fillable = ['code', 'name', 'spoken_name', 'logo_path', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function counters(): HasMany
    {
        return $this->hasMany(Counter::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true)->orderBy('sort_order');
    }
}
