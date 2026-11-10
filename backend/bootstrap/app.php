<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Render terminates HTTPS at its load balancer and forwards plain HTTP
        // with X-Forwarded-* headers. Trusting them lets Laravel know the
        // original request was HTTPS (correct URLs, secure redirects). '*' is
        // fine because the container is only reachable through Render's proxy.
        $middleware->trustProxies(at: '*');

        // There is no server-rendered login page (the SPA has /login). By
        // default the auth middleware builds a redirect to route('login') for
        // any request that does not ask for JSON, and that route does not
        // exist, so a plain GET without a token was a 500. No redirect: the
        // exception renderer below then answers 401 JSON (D33).
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
