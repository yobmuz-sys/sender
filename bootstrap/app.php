<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        // Laravel's own liveness probe. It answers before any application code
        // runs, so it stays available even when the app itself is broken.
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // These values must never be echoed back into a form after a failed
        // submission, and must never be included in exception context.
        $exceptions->dontFlash([
            'current_password',
            'password',
            'password_confirmation',
            'smtp_password',
            'api_secret',
        ]);

        // Debug output is a production data leak, not a helpful default.
        $exceptions->shouldRenderJsonWhen(
            fn ($request): bool => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
