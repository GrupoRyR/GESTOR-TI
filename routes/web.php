<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::inertia('/dashboard', 'Dashboard')->name('dashboard');

// TEMPORARY (replaced in A5): named `login` route so the `auth` middleware
// can redirect guests. Microsoft and emergency login arrive in A5.
Route::inertia('/login', 'Auth/Login')->middleware('guest')->name('login');

// Module routes: each group is gated by `module:{key}` (404 when the module
// is inactive in config/modules.php) and `auth`.
// TEMPORARY placeholders: replaced by real controllers in B (ai), C (assistant)
// and the administration tasks of stage A (admin).
Route::middleware(['module:ai', 'auth'])->prefix('ai')->name('ai.')->group(function () {
    Route::inertia('/', 'AI/Index')->name('index');
});

Route::middleware(['module:assistant', 'auth'])->prefix('assistant')->name('assistant.')->group(function () {
    Route::inertia('/', 'Assistant/Index')->name('index');
});

Route::middleware(['module:admin', 'auth'])->prefix('admin')->name('admin.')->group(function () {
    Route::inertia('/', 'Admin/Index')->name('index');
});
