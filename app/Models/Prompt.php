<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\BelongsToAccount;

class Prompt extends Model
{
    use BelongsToAccount;

    const UPDATED_AT = 'updated_at';
    const CREATED_AT = null;

    protected $fillable = [
        'account_id',
        'category',
        'persona_id',
        'prompt',
        'source',
        'deleted_at',
        'lang',
        'geo',
        'first_seen',
        'last_seen',
        'status',
        'serp_features',
        'trend_delta',
        'search_volume',
        'is_paused',
        'score_auto',
        'topic_cluster',
        'hash_norm',
        'notes',
        'created_by',
        'volume_checked_at',
    ];

    protected $casts = [
        'serp_features' => 'array',
        'is_paused' => 'boolean',
        'score_auto' => 'decimal:2',
        'trend_delta' => 'decimal:2',
        'first_seen' => 'datetime',
        'last_seen' => 'datetime',
        'volume_checked_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(Response::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_paused', false)->whereNull('deleted_at');
    }

    public function scopeNotDeleted($query)
    {
        return $query->whereNull('deleted_at');
    }
    
}