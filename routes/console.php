<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Finished work leaves the boards on its own. Idempotent, so a missed run or a
// double run is harmless; the window itself is set in the admin panel.
Schedule::command('tasks:archive')->dailyAt('03:00');
