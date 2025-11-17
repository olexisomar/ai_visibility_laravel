<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\User;

class AuthenticateApiKey
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Get API key from query string or header
        $apiKey = $request->query('api_key') ?? $request->header('X-API-Key');

        if (!$apiKey) {
            return response()->json([
                'message' => 'API key is required'
            ], 401);
        }

        // Find user by API key
        $user = User::where('api_key', $apiKey)->first();

        if (!$user) {
            return response()->json([
                'message' => 'Invalid API key'
            ], 401);
        }

        // Log the user in for this request
        auth()->login($user);

        return $next($request);
    }
}