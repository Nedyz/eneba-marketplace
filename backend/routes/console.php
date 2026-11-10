<?php

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
