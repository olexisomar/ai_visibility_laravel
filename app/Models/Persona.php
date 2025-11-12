<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Traits\BelongsToAccount;

class Persona extends Model
{
    use BelongsToAccount;

    protected $fillable = [
        'account_id',
        'name',
        'brand_id',
        'description',
        'attributes',
        'is_active',
        'is_deleted',
    ];

    protected $casts = [
        'attributes' => 'array',
        'is_active' => 'boolean',
        'is_deleted' => 'boolean',
    ];

    // ========== EXISTING RELATIONSHIPS ==========
    
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id', 'id');
    }

    public function prompts(): HasMany
    {
        return $this->hasMany(Prompt::class);
    }

    public function rawSuggestions(): HasMany
    {
        return $this->hasMany(RawSuggestion::class);
    }

    // ========== NEW RELATIONSHIP (ADDED) ==========
    
    /**
     * Get topics mapped to this persona
     */
    public function topics(): BelongsToMany
    {
        return $this->belongsToMany(Topic::class, 'topic_persona')
                    ->withTimestamps();
    }

    /**
     * Get only active topics for this persona
     */
    public function activeTopics(): BelongsToMany
    {
        return $this->topics()
                    ->where('topics.is_active', true)
                    ->where('topics.is_deleted', false);
    }

    // ========== EXISTING SCOPE (UNCHANGED) ==========
    
    public function scopeActive($query)
    {
        return $query->where('is_active', true)->where('is_deleted', false);
    }

    // ========== NEW HELPER METHODS (OPTIONAL) ==========
    
    /**
     * Check if persona is mapped to any topics
     */
    public function hasTopics(): bool
    {
        return $this->topics()->exists();
    }

    /**
     * Get count of mapped topics
     */
    public function topicsCount(): int
    {
        return $this->topics()->count();
    }
}