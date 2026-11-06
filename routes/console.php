<?php

use App\Modules\Identity\Models\DeviceLoginChallenge;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('model:prune', ['--model' => [DeviceLoginChallenge::class]])->daily();

// Expired tokens (staff after a day, candidates after their exam) are deleted a day later.
Schedule::command('sanctum:prune-expired', ['--hours' => 24])->daily();

// Exams: absences when the late window closes, attempts closed at their deadline (D-073).
Schedule::command('exams:sweep')->everyMinute()->withoutOverlapping();

// Contracts ending within two weeks: one in-system notification each (D-065).
Schedule::command('contracts:remind-expiring')->dailyAt('06:00');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
