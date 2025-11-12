<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;

class AuthController extends Controller
{
    /**
     * Show login form
     */
    public function showLogin()
    {
        // If already logged in, redirect to dashboard
        if (Auth::check()) {
            return redirect('/');
        }

        return view('auth.login');
    }

    /**
     * Handle login request
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        // Check if user exists and is active
        $user = User::where('email', $credentials['email'])->first();

        if (!$user) {
            return back()->withErrors([
                'email' => 'No account found with this email.',
            ])->withInput();
        }

        if (!$user->is_active) {
            return back()->withErrors([
                'email' => 'Your account has been deactivated.',
            ])->withInput();
        }

        // Attempt authentication
        if (!Hash::check($credentials['password'], $user->password)) {
            return back()->withErrors([
                'password' => 'Incorrect password.',
            ])->withInput();
        }

        // Login successful
        Auth::login($user, $request->boolean('remember'));

        // Update last login
        $user->update(['last_login_at' => now()]);

        // Regenerate session
        $request->session()->regenerate();

        // Get user's accounts
        $accounts = $user->is_super_admin 
            ? \App\Models\Account::where('is_active', true)->get()
            : $user->accounts()->where('is_active', true)->get();

        // If user has no accounts
        if ($accounts->isEmpty()) {
            Auth::logout();
            return back()->withErrors([
                'email' => 'You are not assigned to any accounts.',
            ]);
        }

        // If user has only 1 account, auto-select it
        if ($accounts->count() === 1) {
            $account = $accounts->first();
            
            session([
                'account_id' => $account->id,
                'account_name' => $account->name,
                'account_slug' => $account->slug,
                'user_role' => $user->getRoleForAccount($account->id),
            ]);

            return redirect()->intended('/');
        }

        // If user has multiple accounts, show account selection
        return redirect()->route('account.select');
    }

    /**
     * Show account selection page
     */
    public function showAccountSelect()
    {
        $user = Auth::user();

        $accounts = $user->is_super_admin 
            ? \App\Models\Account::where('is_active', true)->get()
            : $user->accounts()->where('is_active', true)->get();

        return view('auth.select-account', compact('accounts'));
    }

    /**
     * Handle account selection
     */
    public function selectAccount(Request $request)
    {
        $request->validate([
            'account_id' => 'required|integer|exists:accounts,id',
        ]);

        $user = Auth::user();
        $accountId = $request->input('account_id');

        // Verify user has access to this account
        if (!$user->hasAccessToAccount($accountId)) {
            return back()->withErrors([
                'account_id' => 'You do not have access to this account.',
            ]);
        }

        $account = \App\Models\Account::findOrFail($accountId);

        // Set account in session
        session([
            'account_id' => $account->id,
            'account_name' => $account->name,
            'account_slug' => $account->slug,
            'user_role' => $user->getRoleForAccount($account->id),
        ]);

        return redirect('/');
    }

    /**
     * Switch to different account
     */
    public function switchAccount(Request $request)
    {
        $request->validate([
            'account_id' => 'required|integer|exists:accounts,id',
        ]);

        $user = Auth::user();
        $accountId = $request->input('account_id');

        // Verify user has access
        if (!$user->hasAccessToAccount($accountId)) {
            return response()->json([
                'success' => false,
                'message' => 'Access denied',
            ], 403);
        }

        $account = \App\Models\Account::findOrFail($accountId);

        // Update session
        session([
            'account_id' => $account->id,
            'account_name' => $account->name,
            'account_slug' => $account->slug,
            'user_role' => $user->getRoleForAccount($account->id),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Account switched successfully',
            'account' => [
                'id' => $account->id,
                'name' => $account->name,
                'slug' => $account->slug,
            ],
        ]);
    }

    /**
     * Logout
     */
    public function logout(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}