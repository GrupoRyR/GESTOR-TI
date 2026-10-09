<?php

use App\Http\Controllers\Admin\AreaController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\IntegrationController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AI\AcceptanceValidationController;
use App\Http\Controllers\AI\ApprovalController;
use App\Http\Controllers\AI\AreaBoardController;
use App\Http\Controllers\AI\BacklogController;
use App\Http\Controllers\AI\InboxController;
use App\Http\Controllers\AI\RatingController;
use App\Http\Controllers\AI\RequestController;
use App\Http\Controllers\AI\RequestInteractionController;
use App\Http\Controllers\AI\RequestWizardController;
use App\Http\Controllers\AI\RoadmapController;
use App\Http\Controllers\AI\StageBPlaceholderController;
use App\Http\Controllers\AI\TriageController;
use App\Http\Controllers\AI\WeekController;
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

    // Requester pages (B3, types/pages/ai-requester.ts). `{request}` is the
    // key (GIA-n), bound through CompanyScope (other companies answer 404);
    // abilities live in AiRequestPolicy.
    Route::middleware('can:ai.requests.create')->group(function () {
        Route::get('/requests/create', [RequestWizardController::class, 'create'])->name('requests.create');
        Route::put('/requests/draft', [RequestWizardController::class, 'updateDraft'])->name('requests.draft.update');
        Route::post('/requests', [RequestWizardController::class, 'store'])->name('requests.store');
        Route::get('/requests/mine', [RequestController::class, 'mine'])->name('requests.mine');
    });
    Route::get('/roadmap', RoadmapController::class)->name('roadmap.index');

    Route::get('/requests/{request}', [RequestController::class, 'show'])->name('requests.show');
    Route::get('/requests/{request}/submitted', [RequestWizardController::class, 'submitted'])->name('requests.submitted');
    Route::post('/requests/{request}/vote', [RequestInteractionController::class, 'vote'])->name('requests.vote');
    Route::delete('/requests/{request}/vote', [RequestInteractionController::class, 'unvote'])->name('requests.vote.destroy');
    Route::post('/requests/{request}/follow', [RequestInteractionController::class, 'follow'])->name('requests.follow');
    Route::delete('/requests/{request}/follow', [RequestInteractionController::class, 'unfollow'])->name('requests.follow.destroy');
    Route::post('/requests/{request}/comments', [RequestInteractionController::class, 'comment'])->name('requests.comments.store');
    // Signed links only (FileRef.url, 30 minutes), then authorized again.
    Route::get('/requests/{request}/attachments/{attachment}', [RequestInteractionController::class, 'attachment'])
        ->middleware('signed')
        ->scopeBindings()
        ->name('requests.attachments.show');
    // GET not named by the design contract (no link on the pages yet): notifications use it.
    Route::get('/requests/{request}/validation', [AcceptanceValidationController::class, 'edit'])->name('requests.validation.edit');
    Route::put('/requests/{request}/validation', [AcceptanceValidationController::class, 'update'])->name('requests.validation.update');
    Route::get('/requests/{request}/rating', [RatingController::class, 'create'])->name('ratings.create');
    Route::post('/requests/{request}/rating', [RatingController::class, 'store'])->name('ratings.store');
    // GET not named by the design contract: the 30-day reminder (B7) links to it.
    Route::get('/requests/{request}/hours-saved', [RatingController::class, 'createHours'])->name('ratings.hours.create');
    Route::post('/requests/{request}/hours-saved', [RatingController::class, 'storeHours'])->name('ratings.hours.store');

    // IT triage (B4, types/pages/ai-triage.ts): `ai.manage`. Each action
    // re-checks the request with AiRequestPolicy (company access).
    Route::middleware('can:ai.manage')->group(function () {
        Route::get('/inbox', [InboxController::class, 'index'])->name('inbox.index');
        // Inicio "Ver todas" (types/pages/home.ts): redirects to the inbox chip or Mi semana.
        Route::get('/requests', [InboxController::class, 'requests'])->name('requests.index');
        // Bulk bar: registered before `/requests/{request}/…` ("bulk" is not a key).
        Route::post('/requests/bulk/ask', [InboxController::class, 'bulkAsk'])->name('requests.bulk.ask');
        Route::post('/requests/bulk/assign', [InboxController::class, 'bulkAssign'])->name('requests.bulk.assign');

        Route::put('/requests/{request}/evaluation', [TriageController::class, 'evaluate'])->name('requests.evaluation.update');
        Route::post('/requests/{request}/notes', [TriageController::class, 'note'])->name('requests.notes.store');
        Route::post('/requests/{request}/reject', [TriageController::class, 'reject'])->name('requests.reject');
        Route::post('/requests/{request}/ask', [TriageController::class, 'ask'])->name('requests.ask');
        Route::post('/requests/{request}/merge', [TriageController::class, 'merge'])->name('requests.merge');
        Route::post('/requests/{request}/approval', [TriageController::class, 'sendToApproval'])->name('requests.approval.store');
        // Not in the design contract yet: "Mover al backlog" (flagged for design).
        Route::post('/requests/{request}/backlog', [TriageController::class, 'backlog'])->name('requests.backlog');
        Route::post('/requests/{request}/remind', [TriageController::class, 'remind'])->name('requests.remind');
        Route::post('/approvals/{request}/remind', [ApprovalController::class, 'remind'])->name('approvals.remind');

        Route::get('/backlog', [BacklogController::class, 'index'])->name('backlog.index');
        Route::put('/backlog/order', [BacklogController::class, 'reorder'])->name('backlog.reorder');
        Route::delete('/backlog/order', [BacklogController::class, 'reset'])->name('backlog.manual');
        Route::get('/areas', [AreaBoardController::class, 'index'])->name('areas.index');
        Route::get('/week', WeekController::class)->name('week.index');
    });

    // Project approvals (B4): pages for every module user (the index renders
    // NoPermission without `ai.projects.approve`, the show answers 403);
    // deciding needs `ai.projects.approve` (Súper Admin does not inherit it).
    Route::get('/approvals', [ApprovalController::class, 'index'])->name('approvals.index');
    Route::get('/approvals/{request}', [ApprovalController::class, 'show'])->name('approvals.show');
    Route::middleware('can:ai.projects.approve')->group(function () {
        Route::post('/approvals/{request}/approve', [ApprovalController::class, 'approve'])->name('approvals.approve');
        Route::post('/approvals/{request}/reject', [ApprovalController::class, 'reject'])->name('approvals.reject');
    });

    // TEMPORARY (Stage B): routes the B4 pages and the GestorLayout tabs
    // already call, implemented by later tasks (projects/tasks/requirements
    // and weekly review B5, timer B6, docs/value B7, vault B8, products B9).
    // Pages redirect to Inicio and actions go back, both with a notice.
    Route::get('/requests/{request}/tasks/{task}', StageBPlaceholderController::class)->name('tasks.show');
    Route::get('/requirements/{requirement}', StageBPlaceholderController::class)->name('requirements.show');
    Route::post('/requirements/{requirement}/remind', StageBPlaceholderController::class)->name('requirements.remind');
    Route::post('/requests/{request}/convert', StageBPlaceholderController::class)->name('requests.convert');
    Route::post('/projects/{project}/tasks/{task}/timer', StageBPlaceholderController::class)->name('timer.start');
    Route::post('/timer/stop', StageBPlaceholderController::class)->name('timer.stop');
    Route::post('/reviews', StageBPlaceholderController::class)->name('reviews.store');
    Route::get('/vault', StageBPlaceholderController::class)->name('vault.index');
    Route::get('/projects', StageBPlaceholderController::class)->name('projects.index');
    Route::get('/projects/{project}', StageBPlaceholderController::class)->name('projects.show');
    Route::get('/products', StageBPlaceholderController::class)->name('products.index');
    Route::get('/products/{product}', StageBPlaceholderController::class)->name('products.show');
    Route::get('/docs', StageBPlaceholderController::class)->name('docs.index');
    Route::get('/value', StageBPlaceholderController::class)->name('value.index');
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

    // Integraciones (`Admin/Integrations/Index`): Súper Admin (`settings.manage`).
    // Test buttons: 5 per minute per user (shared bucket).
    Route::middleware('can:settings.manage')->prefix('integrations')->name('integrations.')->group(function () {
        Route::get('/', [IntegrationController::class, 'index'])->name('index');
        Route::middleware('throttle:5,1,integrations-test')->group(function () {
            Route::post('/microsoft/test', [IntegrationController::class, 'testMicrosoft'])->name('microsoft.test');
            Route::post('/mail/test', [IntegrationController::class, 'testMail'])->name('mail.test');
            Route::post('/telegram/test', [IntegrationController::class, 'testTelegram'])->name('telegram.test');
        });
        Route::put('/github', [IntegrationController::class, 'updateGithub'])->name('github.update');
        Route::put('/ai', [IntegrationController::class, 'updateAi'])->name('ai.update');
    });

    // Usuarios (`Admin/Users/Index`). Users outside the admin's company
    // scope answer 404 (UserDirectory).
    Route::middleware('can:users.manage')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/{user}', [UserController::class, 'show'])->whereNumber('user')->name('users.show');
        Route::put('/users/{user}/roles', [UserController::class, 'updateRoles'])->whereNumber('user')->name('users.roles.update');
        Route::patch('/users/{user}/deactivate', [UserController::class, 'deactivate'])->whereNumber('user')->name('users.deactivate');
    });

    // Roles y permisos (`Admin/Roles/Index`): matrix saved in one PUT and
    // custom roles. `ai.vault.access` is rejected by the form request and
    // by the Role model.
    Route::middleware('can:roles.manage')->group(function () {
        Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
        Route::put('/roles', [RoleController::class, 'update'])->name('roles.update');
        Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
    });

    // Auditoría (`Admin/Audit/Index`) and its CSV export: restricted users
    // only see activities caused by users of their company; properties are
    // sanitized again (App\Support\Audit\AuditEntries).
    Route::middleware('can:audit.view')->group(function () {
        Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');
        Route::get('/audit/export', [AuditController::class, 'export'])->name('audit.export');
    });
});
