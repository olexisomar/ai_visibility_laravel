<?php

// Auto-clear config cache to prevent APP_KEY errors
if (file_exists(__DIR__ . '/../.env')) {
    $cachedConfig = __DIR__ . '/cache/config.php';
    if (file_exists($cachedConfig)) {
        @unlink($cachedConfig);
    }
}

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);
        
        // Exclude API routes from CSRF
        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);

        $middleware->alias([
            'super_admin' => \App\Http\Middleware\SuperAdminMiddleware::class,
            'account.scope' => \App\Http\Middleware\AccountScopeMiddleware::class,
            'api.auth' => \App\Http\Middleware\ApiAuthenticationMiddleware::class,
            'auth.api-key' => \App\Http\Middleware\AuthenticateApiKey::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();