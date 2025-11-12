<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\BelongsToAccount;

class Brand extends Model
{
    use BelongsToAccount;

    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'name',
        'account_id',
    ];

    public function aliases(): HasMany
    {
        return $this->hasMany(BrandAlias::class, 'brand_id', 'id');
    }

    public function mentions(): HasMany
    {
        return $this->hasMany(Mention::class, 'brand_id', 'id');
    }

    public function personas(): HasMany
    {
        return $this->hasMany(Persona::class, 'brand_id', 'id');
    }
}