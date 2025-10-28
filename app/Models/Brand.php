<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Brand extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id',
        'name',
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