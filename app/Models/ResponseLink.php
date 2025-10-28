<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResponseLink extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'response_id',
        'url',
        'anchor',
        'source',
        'domain',
    ];

    public function response(): BelongsTo
    {
        return $this->belongsTo(Response::class);
    }
}