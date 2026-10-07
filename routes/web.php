<?php

use App\Http\Controllers\Admin\AreaController;
use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserRoleController;
use App\Http\Controllers\Auth\EmergencyLoginController;
use App\Http\Controllers\Auth\MicrosoftLoginController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\CompanySwitchController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::inertia('/dashboard', 'Dashboard')->middleware('auth')->name('dashboard');

// Authentication (A5). `throttle:login` = per-IP limit (AppServiceProvider);
// the emergency login adds its own 5-failure lockout.
Route::get('/login', [SessionController::class, 'create'])->middleware('guest')->name('login');
Route::post('/logout', [SessionController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware('throttle:login')->group(function () {
    Route::get('/auth/microsoft/redirect', [MicrosoftLoginController::class, 'redirect'])
        ->middleware('guest')
        ->name('auth.microsoft.redirect');
    // Serves both sign-in and the vault re-authentication.
    Route::get('/auth/microsoft/callback', [MicrosoftLoginController::class, 'callback'])
        ->name('auth.microsoft.callback');
    Route::get('/auth/microsoft/reauth', [MicrosoftLoginController::class, 'reauth'])
        ->middleware('auth')
        ->name('auth.microsoft.reauth');

    // Emergency local login: 404 while EMERGENCY_ADMIN_EMAIL is not set.
    Route::middleware('guest')->group(function () {
        Route::get('/emergency-login', [EmergencyLoginController::class, 'create'])->name('emergency.login');
        Route::post('/emergency-login', [EmergencyLoginController::class, 'store'])->name('emergency.login.store');
    });
});

// Company selector of the top bar (only `companies.view_all`; null = "Todas").
Route::post('/company/switch', CompanySwitchController::class)
    ->middleware('auth')
    ->name('company.switch');

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

    // Creating a company or changing its tenant also requires
    // `companies.manage_tenant` (checked in the form requests).
    Route::middleware('can:companies.manage')->group(function () {
        Route::get('/companies', [CompanyController::class, 'index'])->name('companies.index');
        Route::post('/companies', [CompanyController::class, 'store'])->name('companies.store');
        Route::put('/companies/{company}', [CompanyController::class, 'update'])->name('companies.update');
    });

    Route::middleware('can:areas.manage')->group(function () {
        Route::get('/areas', [AreaController::class, 'index'])->name('areas.index');
        Route::post('/areas', [AreaController::class, 'store'])->name('areas.store');
        Route::put('/areas/{area}', [AreaController::class, 'update'])->name('areas.update');
    });

    // Role × permission matrix. Exclusive permissions (`ai.vault.access`)
    // are rejected by the form request and by the Role model.
    Route::middleware('can:roles.manage')->group(function () {
        Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
        Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
    });

    Route::put('/users/{user}/roles', [UserRoleController::class, 'update'])
        ->middleware('can:users.manage')
        ->name('users.roles.update');
});
