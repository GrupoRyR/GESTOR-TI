<?php

use App\Http\Controllers\Admin\AdminTabPlaceholderController;
use App\Http\Controllers\Admin\AreaController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserRoleController;
use App\Http\Controllers\AI\StageBPlaceholderController;
use App\Http\Controllers\Auth\EmergencyLoginController;
use App\Http\Controllers\Auth\MicrosoftLoginController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\CompanySwitchController;
use App\Http\Controllers\Employees\EmployeeAccessController;
use App\Http\Controllers\Employees\EmployeeController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\Profile\ProfileController;
use App\Http\Controllers\Profile\TelegramLinkController;
use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

// Inicio (`Home`): IT board or requester home.
Route::get('/', HomeController::class)->middleware('auth')->name('home');

// Top-bar search. Stage A: Colaboradores only (see SearchController).
Route::get('/search', SearchController::class)->middleware('auth')->name('search');

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
        Route::get('/emergency-login', [EmergencyLoginController::class, 'create'])->name('login.emergency');
        Route::post('/emergency-login', [EmergencyLoginController::class, 'store'])->name('login.emergency.store');
    });
});

// Company selector of the top bar (only `companies.view_all`; null = "Todas").
Route::post('/company/switch', CompanySwitchController::class)
    ->middleware('auth')
    ->name('company.switch');

// Notifications (A7): bell. Perfil (`Profile/Edit`, `Profile/TelegramLink`):
// any signed-in user, no extra permission.
Route::middleware('auth')->group(function () {
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read', [NotificationController::class, 'read'])->name('notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read_all');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    // One checkbox of the event × channel matrix: `{event, channel, enabled}`.
    Route::put('/profile/notifications', [ProfileController::class, 'updateNotification'])->name('profile.notifications.update');
    Route::get('/profile/telegram', [TelegramLinkController::class, 'create'])->name('profile.telegram.create');
    Route::post('/profile/telegram', [TelegramLinkController::class, 'store'])->name('profile.telegram.store');
    Route::delete('/profile/telegram', [TelegramLinkController::class, 'destroy'])->name('profile.telegram.destroy');
});

// Module routes: each group is gated by `module:{key}` (404 when the module
// is inactive in config/modules.php) and `auth`.
// TEMPORARY placeholders: replaced by real controllers in B (ai), C (assistant)
// and the administration tasks of stage A (admin).
Route::middleware(['module:ai', 'auth'])->prefix('ai')->name('ai.')->group(function () {
    Route::inertia('/', 'AI/Index')->name('index');

    // TEMPORARY (Stage B): routes the Stage A design links to (types/pages/home.ts).
    // They redirect to Inicio with a notice until Stage B replaces them.
    Route::get('/requests', StageBPlaceholderController::class)->name('requests.index');
    Route::get('/requests/create', StageBPlaceholderController::class)->name('requests.create');
    Route::get('/requests/{request}', StageBPlaceholderController::class)->name('requests.show');
    Route::get('/requests/{request}/tasks/{task}', StageBPlaceholderController::class)->name('tasks.show');
    Route::get('/requests/{request}/rating', StageBPlaceholderController::class)->name('ratings.create');
    Route::get('/requirements/{requirement}', StageBPlaceholderController::class)->name('requirements.show');
    Route::get('/approvals/{request}', StageBPlaceholderController::class)->name('approvals.show');
    Route::get('/vault', StageBPlaceholderController::class)->name('vault.index');
});

// Colaboradores (`Employees/Index`). Bindings honour CompanyScope; access
// routes are scoped to their employee. Create and sync accept `ai.manage`
// or `employees.manage` (checked in the controller).
Route::middleware(['module:employees', 'auth'])->prefix('employees')->name('employees.')->group(function () {
    Route::get('/', [EmployeeController::class, 'index'])->middleware('can:employees.view')->name('index');
    // TEMPORARY: no design page for manual creation yet (redirects with a notice).
    Route::get('/create', [EmployeeController::class, 'create'])->name('create');
    Route::post('/sync', [EmployeeController::class, 'sync'])->name('sync');

    // Backend page `Admin/Employees/Show` until design ships one.
    Route::get('/{employee}', [EmployeeController::class, 'show'])
        ->middleware('can:employees.view')
        ->whereNumber('employee')
        ->name('show');

    Route::middleware('can:employees.manage')->scopeBindings()->group(function () {
        Route::put('/{employee}', [EmployeeController::class, 'update'])->name('update');
        Route::post('/{employee}/accesses', [EmployeeAccessController::class, 'store'])->name('accesses.store');
        Route::put('/{employee}/accesses/{access}', [EmployeeAccessController::class, 'update'])->name('accesses.update');
        Route::delete('/{employee}/accesses/{access}', [EmployeeAccessController::class, 'destroy'])->name('accesses.destroy');
    });

    // The only JSON endpoint of the module: plain password, audited,
    // `Cache-Control: no-store`, 10 per minute per user.
    Route::post('/{employee}/accesses/{access}/reveal', [EmployeeAccessController::class, 'reveal'])
        ->middleware(['can:credentials.view', 'throttle:credentials-reveal'])
        ->scopeBindings()
        ->name('accesses.reveal');
});

Route::middleware(['module:assistant', 'auth'])->prefix('assistant')->name('assistant.')->group(function () {
    Route::inertia('/', 'Assistant/Index')->name('index');
});

Route::middleware(['module:admin', 'auth'])->prefix('admin')->name('admin.')->group(function () {
    Route::inertia('/', 'Admin/Index')->name('index');

    // Empresas (`Admin/Companies/Index|Create`). Creating a company (wizard
    // included) or changing its tenant also requires `companies.manage_tenant`
    // (controller / form requests). Updates arrive as POST + `_method=PUT`
    // multipart (logos).
    Route::middleware('can:companies.manage')->group(function () {
        Route::get('/companies', [CompanyController::class, 'index'])->name('companies.index');
        Route::get('/companies/create', [CompanyController::class, 'create'])->name('companies.create');
        Route::post('/companies', [CompanyController::class, 'store'])->name('companies.store');
        Route::get('/companies/{company}/edit', [CompanyController::class, 'edit'])->name('companies.edit');
        Route::put('/companies/{company}', [CompanyController::class, 'update'])->name('companies.update');
    });

    // Áreas (`Admin/Areas/Index`), department mappings included. Company
    // access is checked by the controller and form requests (`company_id`).
    Route::middleware('can:areas.manage')->group(function () {
        Route::get('/areas', [AreaController::class, 'index'])->name('areas.index');
        Route::post('/areas', [AreaController::class, 'store'])->name('areas.store');
        Route::put('/areas/mappings', [AreaController::class, 'updateMappings'])->name('areas.mappings.update');
        Route::put('/areas/approval', [AreaController::class, 'updateApproval'])->name('areas.approval.update');
        Route::put('/areas/{area}', [AreaController::class, 'update'])->whereNumber('area')->name('areas.update');
    });

    // TEMPORARY: AdminLayout tabs not wired yet (C6 Usuarios, C7
    // Integraciones); they redirect to Empresas with a notice.
    Route::get('/users', AdminTabPlaceholderController::class)->middleware('can:users.manage')->name('users.index');
    Route::get('/integrations', AdminTabPlaceholderController::class)->middleware('can:companies.manage')->name('integrations.index');

    // Role × permission matrix. Exclusive permissions (`ai.vault.access`)
    // are rejected by the form request and by the Role model.
    Route::middleware('can:roles.manage')->group(function () {
        Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
        Route::put('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
    });

    Route::put('/users/{user}/roles', [UserRoleController::class, 'update'])
        ->middleware('can:users.manage')
        ->name('users.roles.update');

    // Activity log (A8): restricted users only see activities caused by
    // users of their company; properties are sanitized again.
    Route::get('/audit', [AuditController::class, 'index'])
        ->middleware('can:audit.view')
        ->name('audit.index');
});
