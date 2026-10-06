<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Seguimientos y recordatorios de cita (requiere `php artisan schedule:work` o el cron de Laravel).
Schedule::command('agentes:seguimientos')->everyFiveMinutes()->withoutOverlapping();
