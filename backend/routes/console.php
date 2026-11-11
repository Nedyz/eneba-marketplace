<?php

use App\Services\Checkout\OrderExpirer;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Scheduled tasks. They only run where something calls `php artisan
// schedule:run` every minute (cron). Render's free tier has no cron (D10), so
// in production none of these run; each one must therefore be housekeeping
// that correctness never depends on.

// Deletes token rows more than 24 hours past their expiry. Sanctum already
// rejects an expired token on every request (D18), so on Render the rows just
// stay in personal_access_tokens, which is harmless (D33).
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Moves pending and paying orders past their deadline on and releases their
// keys (D10, D34). Without cron, the same happens lazily when an order is read
// or paid, and stock never waits for it (expired reservations count as free).
Artisan::command('orders:expire', function (OrderExpirer $expirer) {
    $this->info("Expired {$expirer->expireAllDue()} order(s).");
})->purpose('Expire pending orders past their reservation and release their keys');

Schedule::command('orders:expire')->everyMinute();
