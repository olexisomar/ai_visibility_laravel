<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ApiAuthenticationMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // If not authenticated, return JSON error instead of redirect
        if (!Auth::check()) {
            return response()->json([
                'error' => 'Unauthenticated',
                'message' => 'Please login to continue',
            ], 401);
        }

        // If no account selected, return JSON error
        if (!session()->has('account_id')) {
            return response()->json([
                'error' => 'No account selected',
                'message' => 'Please select an account',
            ], 403);
        }

        return $next($request);
    }
}