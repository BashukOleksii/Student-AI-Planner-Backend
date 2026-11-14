<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('reminders:dispatch-due --limit=100')->everyMinute();
Schedule::command('reminders:dispatch-email-intents --limit=100')->everyMinute();
Schedule::command('sessions:dispatch-reminders --limit=100')->everyMinute();

// Separate from reminder delivery: this command only creates reviews, never sessions or reminders.
Schedule::command('learning:detect-recovery --limit=10 --days=7')->everyFiveMinutes()->withoutOverlapping(10);
