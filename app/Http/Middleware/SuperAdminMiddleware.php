<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SuperAdminMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if (!auth()->check() || !auth()->user()->is_super_admin) {
            abort(403, 'Unauthorized - Super Admin access required');
        }

        return $next($request);
    }
}