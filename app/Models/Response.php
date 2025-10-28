<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Response extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'run_id',
        'prompt_id',
        'raw_answer',
        'latency_ms',
        'tokens_in',
        'tokens_out',
        'prompt_text',
        'prompt_category',
        'intent',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    public function prompt(): BelongsTo
    {
        return $this->belongsTo(Prompt::class);
    }

    public function mentions(): HasMany
    {
        return $this->hasMany(Mention::class);
    }

    public function links(): HasMany
    {
        return $this->hasMany(ResponseLink::class);
    }
}