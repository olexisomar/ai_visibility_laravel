<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RawSuggestion extends Model
{
    const CREATED_AT = 'collected_at';
    const UPDATED_AT = null;

    protected $fillable = [
        'text',
        'category',
        'persona_id',
        'is_branded',
        'notes',
        'rank',
        'source',
        'lang',
        'geo',
        'seed_term',
        'normalized',
        'hash_norm',
        'topic_cluster',
        'serp_features',
        'confidence',
        'score_auto',
        'status',
        'search_volume',
        'volume_checked_at',
        'approved_at',
        'rejected_at',
        'prompt_id',
    ];

    protected $casts = [
        'is_branded' => 'boolean',
        'notes' => 'array',
        'serp_features' => 'array',
        'score_auto' => 'decimal:2',
        'collected_at' => 'datetime',
        'volume_checked_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    public function prompt(): BelongsTo
    {
        return $this->belongsTo(Prompt::class);
    }

    public function scopeNew($query)
    {
        return $query->where('status', 'new');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }
}