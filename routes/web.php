<?php

use App\Http\Controllers\Admin\AreaController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\CompanyController;
use App\Http\Controllers\Admin\CostController;
use App\Http\Controllers\Admin\IntegrationController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\AI\AcceptanceValidationController;
use App\Http\Controllers\AI\ApprovalController;
use App\Http\Controllers\AI\AreaBoardController;
use App\Http\Controllers\AI\BacklogController;
use App\Http\Controllers\AI\BoardController;
use App\Http\Controllers\AI\DocumentController;
use App\Http\Controllers\AI\InboxController;
use App\Http\Controllers\AI\KnowledgeSearchController;
use App\Http\Controllers\AI\PendingDesignController;
use App\Http\Controllers\AI\ProductController;
use App\Http\Controllers\AI\ProjectController;
use App\Http\Controllers\AI\ProjectCostController;
use App\Http\Controllers\AI\ProjectReportController;
use App\Http\Controllers\AI\RatingController;
use App\Http\Controllers\AI\ReapprovalController;
use App\Http\Controllers\AI\RequestController;
use App\Http\Controllers\AI\RequestInteractionController;
use App\Http\Controllers\AI\RequestWizardController;
use App\Http\Controllers\AI\RequirementController;
use App\Http\Controllers\AI\RoadmapController;
use App\Http\Controllers\AI\StageBPlaceholderController;
use App\Http\Controllers\AI\TaskController;
use App\Http\Controllers\AI\TimeEntryController;
use App\Http\Controllers\AI\TimerController;
use App\Http\Controllers\AI\TriageController;
use App\Http\Controllers\AI\ValueController;
use App\Http\Controllers\AI\VaultController;
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

// Top-bar search: Gestor IA search or Colaboradores (see SearchController).
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

    // Projects (B5, types/pages/ai-projects.ts). `{project}` is the key (P-n)
    // through CompanyScope; `{task}` is the task key inside its project.
    // Pages: AiProjectPolicy::view (IT, team, assignees, area viewers);
    // the list and every change: `ai.manage` + AiProjectPolicy::manage.
    Route::get('/projects', [ProjectController::class, 'index'])->middleware('can:ai.manage')->name('projects.index');
    Route::get('/projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::get('/projects/{project}/board', [BoardController::class, 'board'])->name('projects.board');
    Route::scopeBindings()->group(function () {
        Route::get('/projects/{project}/tasks/{task}', [BoardController::class, 'task'])->name('tasks.show');
        // Signed links only (FileRef.url, 30 minutes), then authorized again.
        Route::get('/projects/{project}/tasks/{task}/attachments/{attachment}', [BoardController::class, 'attachment'])
            ->middleware('signed')
            ->name('tasks.attachments.show');
    });

    Route::middleware('can:ai.manage')->group(function () {
        Route::post('/requests/{request}/convert', [ProjectController::class, 'convert'])->name('requests.convert');

        Route::post('/projects/{project}/tasks', [TaskController::class, 'store'])->name('tasks.store');
        Route::scopeBindings()->group(function () {
            Route::put('/projects/{project}/tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
            Route::put('/projects/{project}/tasks/{task}/move', [TaskController::class, 'move'])->name('tasks.move');
            Route::post('/projects/{project}/tasks/{task}/comments', [TaskController::class, 'comment'])->name('tasks.comments.store');
            Route::post('/projects/{project}/tasks/{task}/attachments', [TaskController::class, 'attach'])->name('tasks.attachments.store');
            Route::post('/projects/{project}/tasks/{task}/subtasks', [TaskController::class, 'storeSubtask'])->name('subtasks.store');
            Route::patch('/projects/{project}/tasks/{task}/subtasks/{subtask}', [TaskController::class, 'toggleSubtask'])->name('subtasks.toggle');
        });

        Route::get('/projects/{project}/requirements', [RequirementController::class, 'index'])->name('projects.requirements');
        Route::post('/projects/{project}/requirements', [RequirementController::class, 'store'])->name('requirements.store');
        Route::post('/requirements/{requirement}/remind', [RequirementController::class, 'remind'])->name('requirements.remind');

        Route::get('/projects/{project}/report', [ProjectReportController::class, 'show'])->name('projects.report');
        Route::get('/projects/{project}/report/pdf', [ProjectReportController::class, 'pdf'])->name('projects.report.pdf');
        Route::post('/projects/{project}/report/send', [ProjectReportController::class, 'send'])->name('projects.report.send');
        Route::put('/projects/{project}/report/schedule', [ProjectReportController::class, 'schedule'])->name('projects.report.schedule');
    });

    // The person who must answer a requirement (any company) or IT:
    // AiRequirementPolicy. `show` is the link of Inicio and the notices.
    Route::get('/requirements/{requirement}', [RequirementController::class, 'show'])->name('requirements.show');
    Route::get('/requirements/{requirement}/respond', [RequirementController::class, 'respond'])->name('requirements.respond');
    Route::post('/requirements/{requirement}/answer', [RequirementController::class, 'answer'])->name('requirements.answer');
    Route::post('/requirements/{requirement}/reassign', [RequirementController::class, 'reassign'])->name('requirements.reassign');

    // Time and costs (B6). Timer and hours: whoever may log hours on the
    // project (AiProjectPolicy::logTime: IT, team, assignees); entries are
    // edited by their owner or IT (editTime). Costs page and export:
    // `ai.manage` + view (money with `ai.costs.view`); costs by hand:
    // `ai.costs.manage` (manageCosts). Re-approval: `ai.manage` + manage.
    Route::scopeBindings()->group(function () {
        Route::post('/projects/{project}/tasks/{task}/timer', [TimerController::class, 'start'])->name('timer.start');
    });
    Route::post('/timer/stop', [TimerController::class, 'stop'])->name('timer.stop');
    Route::get('/time-entries/create', [TimeEntryController::class, 'create'])->name('time-entries.create');
    Route::post('/projects/{project}/time-entries', [TimeEntryController::class, 'store'])->name('time-entries.store');
    // Not in the design contract: edit/delete an entry.
    Route::put('/projects/{project}/time-entries/{entry}', [TimeEntryController::class, 'update'])->whereNumber('entry')->name('time-entries.update');
    Route::delete('/projects/{project}/time-entries/{entry}', [TimeEntryController::class, 'destroy'])->whereNumber('entry')->name('time-entries.destroy');

    Route::middleware('can:ai.manage')->group(function () {
        Route::get('/projects/{project}/costs', [ProjectCostController::class, 'index'])->name('projects.costs');
        Route::get('/projects/{project}/costs/export', [ProjectCostController::class, 'export'])->name('projects.costs.export');
        Route::post('/projects/{project}/reapproval', [ReapprovalController::class, 'store'])->name('projects.reapproval.store');
    });
    Route::middleware('can:ai.costs.manage')->group(function () {
        Route::post('/projects/{project}/costs', [ProjectCostController::class, 'store'])->name('costs.store');
        // Not in the design contract: edit/delete a cost.
        Route::put('/projects/{project}/costs/{cost}', [ProjectCostController::class, 'update'])->whereNumber('cost')->name('costs.update');
        Route::delete('/projects/{project}/costs/{cost}', [ProjectCostController::class, 'destroy'])->whereNumber('cost')->name('costs.destroy');
    });
    // Signed links only (ProjectCost.attachment.url, 30 minutes), then authorized again.
    Route::get('/projects/{project}/costs/{cost}/attachment', [ProjectCostController::class, 'attachment'])
        ->whereNumber('cost')
        ->middleware('signed')
        ->name('costs.attachment');

    // Products, documentation and search (B7, types/pages/ai-knowledge.ts).
    // Pages: `ai.manage` (+ AiProductPolicy / AiDocumentPolicy); writing
    // docs: `ai.docs.edit`. `{product}` is the slug, `{doc}` the id.
    Route::middleware('can:ai.manage')->group(function () {
        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        // TEMPORARY: forms not designed yet (go back with "Disponible pronto.").
        Route::get('/products/create', PendingDesignController::class)->name('products.create');
        Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');
        Route::get('/products/{product}/edit', PendingDesignController::class)->name('products.edit');
        Route::get('/products/{product}/incidents/create', PendingDesignController::class)->name('products.incidents.create');

        Route::get('/docs', [DocumentController::class, 'index'])->name('docs.index');
        Route::get('/docs/create', [DocumentController::class, 'create'])->name('docs.create');
        Route::get('/docs/repo', [DocumentController::class, 'repo'])->name('docs.repo');
        Route::get('/docs/{doc}', [DocumentController::class, 'edit'])->whereNumber('doc')->name('docs.edit');
        Route::get('/docs/{doc}/history', [DocumentController::class, 'history'])->whereNumber('doc')->name('docs.history');
        Route::get('/projects/{project}/docs', [DocumentController::class, 'project'])->name('projects.docs');

        Route::middleware('can:ai.docs.edit')->group(function () {
            Route::post('/docs', [DocumentController::class, 'store'])->name('docs.store');
            Route::put('/docs/{doc}', [DocumentController::class, 'update'])->whereNumber('doc')->name('docs.update');
            Route::post('/docs/{doc}/publish', [DocumentController::class, 'publish'])->whereNumber('doc')->name('docs.publish');
            Route::post('/docs/{doc}/versions/{version}/restore', [DocumentController::class, 'restore'])
                ->whereNumber(['doc', 'version'])
                ->name('docs.restore');
        });
    });
    // Module users (AiProductPolicy::viewAny); docs only reach `ai.manage`.
    Route::get('/search', KnowledgeSearchController::class)->name('search');

    // Value dashboard (B8, types/pages/ai-value-vault.ts): `ai.manage`
    // (Jhon, Leonardo, gerencia); money only with `ai.costs.view`.
    // "Recordar a …" (empty state) asks the requester to rate a delivery.
    Route::middleware('can:ai.manage')->group(function () {
        Route::get('/value', [ValueController::class, 'index'])->name('value.index');
        Route::get('/value/export', [ValueController::class, 'export'])->name('value.export');
        Route::post('/requests/{request}/rating/remind', [RatingController::class, 'remind'])->name('ratings.remind');
    });

    // Credentials vault (B8, PROMPT 6.4.10). `vault` (EnsureVaultAccess):
    // only `ai.vault.access` (others get Ai/Vault/Denied, logged and
    // alerted), fails closed without VAULT_KEY, auto-locks after 10 minutes,
    // encrypted history and no-store. Unlocking needs a fresh Microsoft
    // sign-in (`reauth:10`). `{item}` is the item id.
    Route::middleware('vault')->prefix('vault')->name('vault.')->group(function () {
        Route::get('/', [VaultController::class, 'index'])->name('index');
        Route::get('/unlock', [VaultController::class, 'unlock'])->middleware('reauth:10')->name('reauth');
        Route::post('/lock', [VaultController::class, 'lock'])->name('lock');
        Route::post('/{item}/reveal', [VaultController::class, 'reveal'])->whereNumber('item')->middleware('throttle:20,1')->name('reveal');
        Route::post('/{item}/copied', [VaultController::class, 'copied'])->whereNumber('item')->name('copied');
        // TEMPORARY: the item form is not designed yet (goes back with "Disponible pronto.").
        Route::get('/{item}/edit', PendingDesignController::class)->whereNumber('item')->name('edit');
    });

    // TEMPORARY (Stage B): the weekly review has no design for its storage
    // yet; it goes back with a notice.
    Route::post('/reviews', StageBPlaceholderController::class)->name('reviews.store');
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

    // Costos y aprobaciones (`Admin/Costs/Index`, Gestor IA): the page for
    // `ai.costs.view`, `ai.manage` or `admin.access` (money only with
    // `ai.costs.view`, checked by the controller); rates and area hour
    // values `ai.costs.manage`; approvers and settings `ai.manage`.
    Route::prefix('costs')->name('costs.')->group(function () {
        Route::get('/', [CostController::class, 'index'])->name('index');
        Route::middleware('can:ai.costs.manage')->group(function () {
            Route::put('/rates/{user}', [CostController::class, 'updateRate'])->whereNumber('user')->name('rates.update');
            Route::put('/area-values', [CostController::class, 'updateAreaValues'])->name('area-values.update');
        });
        Route::middleware('can:ai.manage')->group(function () {
            Route::post('/approvers', [CostController::class, 'storeApprover'])->name('approvers.store');
            Route::delete('/approvers/{user}', [CostController::class, 'destroyApprover'])->whereNumber('user')->name('approvers.destroy');
            Route::put('/settings', [CostController::class, 'updateSettings'])->name('settings.update');
        });
    });

    // Auditoría (`Admin/Audit/Index`) and its CSV export: restricted users
    // only see activities caused by users of their company; properties are
    // sanitized again (App\Support\Audit\AuditEntries).
    Route::middleware('can:audit.view')->group(function () {
        Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');
        Route::get('/audit/export', [AuditController::class, 'export'])->name('audit.export');
    });
});
