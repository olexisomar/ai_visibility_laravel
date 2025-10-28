<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Topic extends Model
{
    protected $fillable = [
        'name',
        'is_active',
        'notes',
        'last_generated_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_generated_at' => 'datetime',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}