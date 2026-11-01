<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Modules\Identity\Models\DeviceLoginChallenge;
use Illuminate\Support\Facades\Schedule;

Schedule::command('model:prune', ['--model' => [DeviceLoginChallenge::class]])->daily();

// Contracts ending within two weeks: one in-system notification each (D-065).
Schedule::command('contracts:remind-expiring')->dailyAt('06:00');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
