<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable
{
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_super_admin',
        'is_active',
        'created_by',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'is_super_admin' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Accounts this user belongs to
     */
    public function accounts()
    {
        return $this->belongsToMany(Account::class, 'account_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Check if user is super admin
     */
    public function isSuperAdmin(): bool
    {
        return $this->is_super_admin;
    }

    /**
     * Check if user has access to account
     */
    public function hasAccessToAccount(int $accountId): bool
    {
        if ($this->is_super_admin) {
            return true;
        }

        return $this->accounts()->where('accounts.id', $accountId)->exists();
    }

    /**
     * Check if user is admin of account
     */
    public function isAdminOfAccount(int $accountId): bool
    {
        if ($this->is_super_admin) {
            return true;
        }

        return $this->accounts()
            ->where('accounts.id', $accountId)
            ->wherePivot('role', 'admin')
            ->exists();
    }

    /**
     * Get user's role for specific account
     */
    public function getRoleForAccount(int $accountId): ?string
    {
        if ($this->is_super_admin) {
            return 'admin';
        }

        $account = $this->accounts()
            ->where('accounts.id', $accountId)
            ->first();

        return $account?->pivot->role;
    }

    /**
     * Check if user can manage accounts (create/edit/delete)
     */
    public function canManageAccounts(): bool
    {
        return $this->is_super_admin || $this->isAdminForAnyAccount();
    }

    /**
     * Check if user is admin for any account
     */
    public function isAdminForAnyAccount(): bool
    {
        return DB::table('account_user')
            ->where('user_id', $this->id)
            ->where('role', 'admin')
            ->exists();
    }

    /**
     * Check if user is admin for specific account
     */
    public function isAdminFor(int $accountId): bool
    {
        if ($this->is_super_admin) {
            return true;
        }
        
        return DB::table('account_user')
            ->where('user_id', $this->id)
            ->where('account_id', $accountId)
            ->where('role', 'admin')
            ->exists();
    }

    /**
     * Check if user is viewer for specific account
     */
    public function isViewerFor(int $accountId): bool
    {
        if ($this->is_super_admin) {
            return false; // Super admins are never "just viewers"
        }
        
        return DB::table('account_user')
            ->where('user_id', $this->id)
            ->where('account_id', $accountId)
            ->where('role', 'user')
            ->exists();
    }

    /**
     * Check if user can run queries (GPT/AIO) for account
     */
    public function canRunQueries(int $accountId): bool
    {
        return $this->is_super_admin || $this->isAdminFor($accountId);
    }

    /**
     * Check if user can manage prompts/brands for account
     */
    public function canManageContent(int $accountId): bool
    {
        return $this->is_super_admin || $this->isAdminFor($accountId);
    }

    /**
     * Check if user can manage users (create/edit/delete)
     */
    public function canManageUsers(): bool
    {
        return $this->is_super_admin || $this->isAdminForAnyAccount();
    }

    /**
     * Get user's role for specific account
     */
    public function getRoleFor(int $accountId): ?string
    {
        if ($this->is_super_admin) {
            return 'super_admin';
        }
        
        return DB::table('account_user')
            ->where('user_id', $this->id)
            ->where('account_id', $accountId)
            ->value('role');
    }
}