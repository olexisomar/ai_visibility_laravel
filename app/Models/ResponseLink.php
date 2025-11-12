<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\BelongsToAccount;

class ResponseLink extends Model
{
    use BelongsToAccount;

    public $timestamps = false;

    protected $fillable = [
        'account_id',
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