<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Calendar\GoogleCalendarController;
use App\Http\Middleware\SetTenantFromUser;
use App\Livewire\Agenda;
use App\Livewire\Contacts;
use App\Livewire\Inbox;
use App\Livewire\Metrics;
use App\Livewire\Settings\AgentSettings;
use App\Livewire\Settings\BusinessSettings;
use App\Livewire\Settings\FollowupSettings;
use App\Livewire\Settings\KnowledgeSettings;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/ingresar', [LoginController::class, 'show'])->name('login');
    Route::post('/ingresar', [LoginController::class, 'store']);
});

Route::middleware(['auth', SetTenantFromUser::class])->group(function () {
    Route::redirect('/', '/bandeja');
    Route::livewire('/bandeja', Inbox::class)->name('inbox');
    Route::livewire('/agenda', Agenda::class)->name('agenda');
    Route::livewire('/contactos', Contacts::class)->name('contacts');
    Route::livewire('/metricas', Metrics::class)->name('metrics');
    Route::livewire('/configuracion', AgentSettings::class)->name('settings.agent');
    Route::livewire('/configuracion/negocio', BusinessSettings::class)->name('settings.business');
    Route::livewire('/configuracion/conocimiento', KnowledgeSettings::class)->name('settings.knowledge');
    Route::livewire('/configuracion/seguimientos', FollowupSettings::class)->name('settings.followups');
    Route::get('/agenda/google/conectar', [GoogleCalendarController::class, 'connect'])->name('agenda.google.connect');
    Route::get('/agenda/google/callback', [GoogleCalendarController::class, 'callback'])->name('agenda.google.callback');
    Route::post('/agenda/google/desconectar', [GoogleCalendarController::class, 'disconnect'])->name('agenda.google.disconnect');
    Route::post('/salir', [LoginController::class, 'destroy'])->name('logout');
});
