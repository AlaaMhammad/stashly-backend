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

// Removes Idempotency-Key records past their 24-hour replay window.
Schedule::command('model:prune', ['--model' => [IdempotencyRecord::class]])->daily();
