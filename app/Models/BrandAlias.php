<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\BelongsToAccount;

class BrandAlias extends Model
{
    use BelongsToAccount;

    public $timestamps = false;

    protected $fillable = [
        'account_id',
        'brand_id',
        'alias',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id', 'id');
    }
}