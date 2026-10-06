<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Middleware\SetTenantFromUser;
use App\Livewire\Inbox;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/ingresar', [LoginController::class, 'show'])->name('login');
    Route::post('/ingresar', [LoginController::class, 'store']);
});

Route::middleware(['auth', SetTenantFromUser::class])->group(function () {
    Route::redirect('/', '/bandeja');
    Route::livewire('/bandeja', Inbox::class)->name('inbox');
    Route::post('/salir', [LoginController::class, 'destroy'])->name('logout');
});
