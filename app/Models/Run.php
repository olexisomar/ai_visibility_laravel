<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\BelongsToAccount;

class Run extends Model
{
    use BelongsToAccount;

    const CREATED_AT = 'run_at';
    const UPDATED_AT = null;

    protected $fillable = [
        'account_id',
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