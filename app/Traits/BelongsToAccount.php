<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait BelongsToAccount
{
    /**
     * Boot the trait - add global scope
     */
    protected static function bootBelongsToAccount()
    {
        // Automatically scope queries to current account
        static::addGlobalScope('account', function (Builder $builder) {
            if (session()->has('account_id')) {
                $builder->where(
                    $builder->getModel()->getTable() . '.account_id', 
                    session('account_id')
                );
            }
        });

        // Automatically set account_id when creating
        static::creating(function (Model $model) {
            if (!$model->account_id && session()->has('account_id')) {
                $model->account_id = session('account_id');
            }
        });
    }

    /**
     * Relationship to account
     */
    public function account()
    {
        return $this->belongsTo(\App\Models\Account::class);
    }

    /**
     * Scope to specific account
     */
    public function scopeForAccount(Builder $query, int $accountId)
    {
        return $query->where('account_id', $accountId);
    }

    /**
     * Scope to all accounts (bypass global scope)
     */
    public function scopeAllAccounts(Builder $query)
    {
        return $query->withoutGlobalScope('account');
    }
}