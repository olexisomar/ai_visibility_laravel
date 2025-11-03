<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Topic extends Model
{
    protected $fillable = [
        'name',
        'brand_id',           // ← ADDED
        'is_active',
        'is_deleted',         // ← ADDED
        'notes',
        'last_generated_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_deleted' => 'boolean',    // ← ADDED
        'last_generated_at' => 'datetime',
    ];

    // ========== EXISTING SCOPE (UNCHANGED) ==========
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // ========== NEW SCOPES ==========
    
    /**
     * Scope to only non-deleted topics
     */
    public function scopeNotDeleted($query)
    {
        return $query->where('is_deleted', false);
    }

    /**
     * Scope to active and not deleted topics
     */
    public function scopeActiveAndNotDeleted($query)
    {
        return $query->where('is_active', true)
                     ->where('is_deleted', false);
    }

    // ========== NEW RELATIONSHIPS ==========
    
    /**
     * Get personas mapped to this topic
     */
    public function personas()
    {
        return $this->belongsToMany(Persona::class, 'topic_persona')
                    ->withTimestamps();
    }

    /**
     * Get only active personas for this topic
     */
    public function activePersonas()
    {
        return $this->personas()
                    ->where('personas.is_active', true)
                    ->where('personas.is_deleted', false);
    }

    /**
     * Get raw suggestions (pending approval) for this topic
     */
    public function rawSuggestions()
    {
        return $this->hasMany(RawSuggestion::class, 'topic_id');
    }

    /**
     * Get pending suggestions only
     */
    public function pendingSuggestions()
    {
        return $this->rawSuggestions()
                    ->where('status', 'pending');
    }

    /**
     * Get approved prompts for this topic
     */
    public function prompts()
    {
        return $this->hasMany(Prompt::class, 'topic_id');
    }

    /**
     * Get active prompts only
     */
    public function activePrompts()
    {
        return $this->prompts()
                    ->whereNull('deleted_at');
    }

    // ========== HELPER METHODS ==========
    
    /**
     * Count pending suggestions for this topic
     */
    public function pendingSuggestionsCount()
    {
        return $this->pendingSuggestions()->count();
    }

    /**
     * Count approved prompts for this topic
     */
    public function approvedPromptsCount()
    {
        return $this->activePrompts()->count();
    }

    /**
     * Check if topic has any mapped personas
     */
    public function hasPersonas()
    {
        return $this->personas()->exists();
    }

    /**
     * Get count of mapped personas
     */
    public function personasCount()
    {
        return $this->personas()->count();
    }
}