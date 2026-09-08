<?php

use Illuminate\Support\Facades\Schedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('reports:generate-ai-monthly')
    ->monthlyOn(25, '08:00')
    ->withoutOverlapping();

// Sends staged reports whose 5-day review window has ended. Runs daily
// (rather than monthlyOn(30)) so months without a 30th — e.g. February —
// still deliver; the review_until gate inside decides what is actually due.
Schedule::command('reports:send-ai-monthly')
    ->dailyAt('08:05')
    ->withoutOverlapping();

Schedule::command('notifications:scan')
    ->dailyAt('07:00')
    ->withoutOverlapping();
