<?php

use App\Models\IdempotencyRecord;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Contract section 5: scheduled clean-up of expired tokens (DEC-011). Expired
// tokens are already rejected at authentication; this only removes the rows.
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// DEC-006: links stuck in pending metadata for over 15 minutes become failed (STALE_PENDING).
Schedule::command('links:expire-stale-metadata')->everyFiveMinutes()->withoutOverlapping();

// Queue backlog alert source: fires QueueBusy (logged as a warning) above 100 waiting jobs.
$connection = config('queue.default');
Schedule::command('queue:monitor', ["$connection:metadata,$connection:default", '--max=100'])->everyMinute();

// Removes Idempotency-Key records past their 24-hour replay window.
Schedule::command('model:prune', ['--model' => [IdempotencyRecord::class]])->daily();
