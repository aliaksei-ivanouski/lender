<?php

use App\Http\Controllers\EventController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/events-visual-1')->name('home');

Route::get('events/data', [EventController::class, 'data'])->name('events.data');
Route::get('events/{event}', [EventController::class, 'show'])->name('events.show');

Route::get('events-visual-1', [EventController::class, 'visualOne'])->name('events.visual1');
Route::get('events-visual-2', [EventController::class, 'visualTwo'])->name('events.visual2');

Route::inertia('dashboard', 'Dashboard')->name('dashboard');

require __DIR__.'/settings.php';
