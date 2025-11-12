<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Traits\BelongsToAccount;

class RawSuggestion extends Model
{
    use BelongsToAccount;

    const CREATED_AT = 'collected_at';
    const UPDATED_AT = null;

    protected $fillable = [
        'account_id',
        'text',
        'category',
        'persona_id',
        'topic_id',          // ← ADDED
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

    // ========== EXISTING RELATIONSHIPS (UNCHANGED) ==========
    
    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class);
    }

    public function prompt(): BelongsTo
    {
        return $this->belongsTo(Prompt::class);
    }

    // ========== NEW RELATIONSHIP (ADDED) ==========
    
    /**
     * Get the topic this suggestion belongs to
     */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    // ========== EXISTING SCOPES (UNCHANGED) ==========
    
    public function scopeNew($query)
    {
        return $query->where('status', 'new');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    // ========== NEW SCOPES (ADDED) ==========
    
    /**
     * Scope to pending suggestions
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope to rejected suggestions
     */
    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    /**
     * Scope to suggestions for a specific topic
     */
    public function scopeForTopic($query, $topicId)
    {
        return $query->where('topic_id', $topicId);
    }

    /**
     * Scope to suggestions for a specific persona
     */
    public function scopeForPersona($query, $personaId)
    {
        return $query->where('persona_id', $personaId);
    }
}