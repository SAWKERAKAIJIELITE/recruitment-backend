<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Modules\Identity\Models\DeviceLoginChallenge;
use Illuminate\Support\Facades\Schedule;

Schedule::command('model:prune', ['--model' => [DeviceLoginChallenge::class]])->daily();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
