<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'domain',
        'logo_url',
        'is_active',
        'settings',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'settings' => 'array',
    ];

    /**
     * Users in this account
     */
    public function users()
    {
        return $this->belongsToMany(User::class, 'account_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Admins of this account
     */
    public function admins()
    {
        return $this->users()->wherePivot('role', 'admin');
    }

    /**
     * Regular users of this account
     */
    public function regularUsers()
    {
        return $this->users()->wherePivot('role', 'user');
    }

    /**
     * Brands in this account
     */
    public function brands()
    {
        return $this->hasMany(Brand::class);
    }

    /**
     * Prompts in this account
     */
    public function prompts()
    {
        return $this->hasMany(Prompt::class);
    }

    /**
     * Check if account is active
     */
    public function isActive(): bool
    {
        return $this->is_active;
    }
}