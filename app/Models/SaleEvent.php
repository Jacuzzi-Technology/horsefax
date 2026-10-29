<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An upcoming or recent sale on the calendar hf:sale-watch checks against (see App\Harvest\SaleWatch). */
class SaleEvent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'catalog_seen_at' => 'datetime', 'results_seen_at' => 'datetime',
            'last_checked_at' => 'datetime', 'next_check_at' => 'datetime'];
    }

    public function lastDay(): \Illuminate\Support\Carbon
    {
        return $this->ends_on ?? $this->starts_on;
    }
}
