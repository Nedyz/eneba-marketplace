<?php

namespace App\Providers;

use App\Payments\FakePaymentGateway;
use App\Payments\PaymentGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /** Per-IP requests per minute on POST /auth/login (any outcome). */
    public const LOGIN_PER_IP_PER_MINUTE = 30;

    /** Per-IP requests per minute on POST /auth/register. */
    public const REGISTER_PER_IP_PER_MINUTE = 10;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Checkout depends on the interface; this decides which provider it
        // gets (D11). Tests swap in their own fakes with $this->app->instance().
        $this->app->singleton(PaymentGateway::class, FakePaymentGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Named limiters for the throttle:… route middleware (D33), like a
        // rate limiting policy registered in Program.cs. The IP is the real
        // client's because trustProxies reads Render's X-Forwarded-For.
        // A blocked request gets 429 with a Retry-After header.

        // Many emails tried from one address (credential stuffing). Failed
        // attempts per email are limited separately, in LoginRequest.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(self::LOGIN_PER_IP_PER_MINUTE)->by($request->ip()));

        // Mass account creation.
        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(self::REGISTER_PER_IP_PER_MINUTE)->by($request->ip()));
    }
}
