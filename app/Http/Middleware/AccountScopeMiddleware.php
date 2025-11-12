<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AccountScopeMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        // Check if user is authenticated
        if (!Auth::check()) {
            return redirect()->route('login');
        }

        // Check if account is selected in session
        if (!session()->has('account_id')) {
            // Get user's accounts
            $user = Auth::user();
            
            $accounts = $user->is_super_admin 
                ? \App\Models\Account::where('is_active', true)->get()
                : $user->accounts()->where('is_active', true)->get();

            // If no accounts, logout
            if ($accounts->isEmpty()) {
                Auth::logout();
                return redirect()->route('login')->withErrors([
                    'email' => 'You are not assigned to any accounts.',
                ]);
            }

            // If only one account, auto-select
            if ($accounts->count() === 1) {
                $account = $accounts->first();
                
                session([
                    'account_id' => $account->id,
                    'account_name' => $account->name,
                    'account_slug' => $account->slug,
                    'user_role' => $user->getRoleForAccount($account->id),
                ]);

                return $next($request);
            }

            // Multiple accounts - need selection
            return redirect()->route('account.select');
        }

        // Verify user still has access to selected account
        $user = Auth::user();
        $accountId = session('account_id');

        if (!$user->hasAccessToAccount($accountId)) {
            session()->forget(['account_id', 'account_name', 'account_slug', 'user_role']);
            return redirect()->route('account.select')->withErrors([
                'account_id' => 'You no longer have access to the selected account.',
            ]);
        }

        return $next($request);
    }
}