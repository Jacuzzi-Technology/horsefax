<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HarvestPage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fetched_at' => 'datetime'];
    }
}
