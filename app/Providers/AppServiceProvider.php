<?php

namespace App\Providers;

use App\Domain\Assistant\AiDraftParser;
use App\Domain\Assistant\DraftParser;
use App\Domain\Assistant\HeuristicDraftParser;
use App\Models\Permission;
use App\Models\Role;
use App\Notifications\Channels\TelegramChannel;
use App\Services\Ai\AiRuntime;
use App\Support\Audit\AuditOrigin;
use App\Support\Tenancy\CurrentCompany;
use App\Telegram\CallbackRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Microsoft\MicrosoftExtendSocialite;
use Spatie\Activitylog\ActivitylogServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Company context of the request (or of a job, via runAs()).
        $this->app->singleton(CurrentCompany::class);

        // Telegram callback_data prefix => handler (Stage B registers approvals).
        $this->app->singleton(CallbackRegistry::class);

        // `/aprender` parser: the AI one while the provider is ready (key set,
        // under the monthly cap), else the C2 heuristic (decided per use).
        $this->app->bind(DraftParser::class, fn ($app): DraftParser => $app->make(AiRuntime::class)->ready()
            ? $app->make(AiDraftParser::class)
            : $app->make(HeuristicDraftParser::class));

        // Application permission models: they guard the exclusive
        // `ai.vault.access` permission (never attached to a role).
        // Deliberately no Gate::before: Súper Admin must not bypass it.
        config([
            'permission.models.permission' => Permission::class,
            'permission.models.role' => Role::class,
        ]);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(SocialiteWasCalled::class, [MicrosoftExtendSocialite::class, 'handle']);

        // Every activity log entry records where it came from (web, api,
        // telegram, system) and the request IP (Administración › Auditoría).
        $activityModel = ActivitylogServiceProvider::determineActivityModel();
        $activityModel::creating(fn (Model $activity) => AuditOrigin::stamp($activity));

        // Login routes (PROMPT §8): per-IP limit on the Microsoft redirect,
        // callback and reauth, and on the emergency login.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(10)->by((string) $request->ip()));

        // Asistente TI questions (each may call the AI provider): 20 per minute per user.
        RateLimiter::for('assistant-ask', fn (Request $request) => Limit::perMinute(20)
            ->by('assistant-ask:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // "Revelar" contraseña de un acceso: 10 per minute per user.
        RateLimiter::for('credentials-reveal', fn (Request $request) => Limit::perMinute(10)
            ->by('credentials-reveal:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Telegram webhook (PROMPT §8): generous per-IP limit, Telegram
        // delivers bursts from a few addresses.
        RateLimiter::for('telegram-webhook', fn (Request $request) => Limit::perMinute(120)
            ->by('telegram-webhook:'.$request->ip()));

        // `telegram` notification channel (App\Notifications\GestorNotification).
        Notification::resolved(function (ChannelManager $channels): void {
            $channels->extend('telegram', fn ($app) => $app->make(TelegramChannel::class));
        });
    }
}
