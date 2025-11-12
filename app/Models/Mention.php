<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\BelongsToAccount;


class Mention extends Model
{
    use BelongsToAccount;

    public $timestamps = false;

    protected $fillable = [
        'account_id',
        'response_id',
        'brand_id',
        'found_alias',
        'sentiment',
    ];

    public function response(): BelongsTo
    {
        return $this->belongsTo(Response::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id', 'id');
    }
}