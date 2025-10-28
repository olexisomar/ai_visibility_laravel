<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Run extends Model
{
    const CREATED_AT = 'run_at';
    const UPDATED_AT = null;

    protected $fillable = [
        'model',
        'temp',
    ];

    protected $casts = [
        'run_at' => 'datetime',
        'temp' => 'float',
    ];

    public function responses(): HasMany
    {
        return $this->hasMany(Response::class);
    }
}