<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Calendar\GoogleCalendarController;
use App\Http\Middleware\SetTenantFromUser;
use App\Livewire\Agenda;
use App\Livewire\Inbox;
use App\Livewire\Metrics;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/ingresar', [LoginController::class, 'show'])->name('login');
    Route::post('/ingresar', [LoginController::class, 'store']);
});

Route::middleware(['auth', SetTenantFromUser::class])->group(function () {
    Route::redirect('/', '/bandeja');
    Route::livewire('/bandeja', Inbox::class)->name('inbox');
    Route::livewire('/agenda', Agenda::class)->name('agenda');
    Route::livewire('/metricas', Metrics::class)->name('metrics');
    Route::get('/agenda/google/conectar', [GoogleCalendarController::class, 'connect'])->name('agenda.google.connect');
    Route::get('/agenda/google/callback', [GoogleCalendarController::class, 'callback'])->name('agenda.google.callback');
    Route::post('/agenda/google/desconectar', [GoogleCalendarController::class, 'disconnect'])->name('agenda.google.disconnect');
    Route::post('/salir', [LoginController::class, 'destroy'])->name('logout');
});
