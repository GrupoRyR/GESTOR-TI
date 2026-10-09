<?php

namespace App\Services\Alerts;

use App\Models\Alert;
use App\Models\Company;
use App\Models\User;
use App\Notifications\AlertRaised;
use App\Support\Settings;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Generic alerts (PROMPT §2 "puertas abiertas"): used today by the Gestor IA
 * (deadlines, first response) and the vault; later by monitoring and agents.
 *
 * - Level: critical | warning | info. Event type `alert.{level}` decides the
 *   channels per user (config/notifications.php + preferences).
 * - Grouping: an unresolved alert with the same `group_key` is updated
 *   (occurrences, last_seen_at, latest title/body, level escalates) and is
 *   notified again only after the cooldown (`alerts.cooldown_minutes`
 *   setting, default 60). Group keys must be unique across companies (e.g.
 *   include the record key: `ai.first_response:GIA-12`).
 * - Recipients: given explicitly, else users with the permission
 *   `gestor.alerts.recipient_permission` (default `ai.manage`) of the alert's
 *   company plus global users (`is_global` or `companies.view_all`);
 *   group-wide alerts (no company) go to every holder. Deactivated users
 *   (`users.active = false`) are skipped.
 */
class AlertService
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * @param  iterable<User>|null  $recipients
     */
    public function raise(
        string $level,
        string $source,
        string $title,
        ?string $body = null,
        ?Company $company = null,
        ?Model $subject = null,
        ?string $groupKey = null,
        ?iterable $recipients = null,
    ): Alert {
        if (! in_array($level, Alert::LEVELS, true)) {
            throw new InvalidArgumentException("Invalid alert level [{$level}].");
        }

        $now = now();

        [$alert, $notify] = DB::transaction(function () use ($level, $source, $title, $body, $company, $subject, $groupKey, $now): array {
            $existing = $groupKey === null ? null : Alert::query()
                ->where('group_key', $groupKey)
                ->unresolved()
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if ($existing === null) {
                $alert = Alert::create([
                    'company_id' => $company?->id,
                    'level' => $level,
                    'source' => $source,
                    'source_type' => $subject?->getMorphClass(),
                    'source_id' => $subject?->getKey(),
                    'group_key' => $groupKey,
                    'title' => $title,
                    'body' => $body,
                    'occurrences' => 1,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                ]);

                return [$alert, true];
            }

            $existing->fill([
                'title' => $title,
                'body' => $body,
                'occurrences' => $existing->occurrences + 1,
                'last_seen_at' => $now,
            ]);

            if (Alert::severity($level) < Alert::severity($existing->level)) {
                $existing->level = $level;
            }

            $existing->save();

            return [$existing, $this->cooldownElapsed($existing)];
        });

        if ($notify) {
            $this->notify($alert, $recipients);
        }

        return $alert;
    }

    /**
     * Resolves every unresolved alert of the group. Returns how many.
     */
    public function resolve(string $groupKey): int
    {
        return Alert::query()
            ->where('group_key', $groupKey)
            ->unresolved()
            ->update(['resolved_at' => now(), 'updated_at' => now()]);
    }

    private function cooldownElapsed(Alert $alert): bool
    {
        if ($alert->last_notified_at === null) {
            return true;
        }

        $minutes = (int) $this->settings->get('alerts.cooldown_minutes', 60);

        return $alert->last_notified_at->lte(now()->subMinutes($minutes));
    }

    /**
     * @param  iterable<User>|null  $recipients
     */
    private function notify(Alert $alert, ?iterable $recipients): void
    {
        $users = $recipients === null ? $this->defaultRecipients($alert) : collect($recipients);

        $alert->forceFill(['last_notified_at' => now()])->save();

        if ($users->isNotEmpty()) {
            Notification::send($users, new AlertRaised($alert));
        }
    }

    /**
     * @return Collection<int, User>
     */
    private function defaultRecipients(Alert $alert): Collection
    {
        $permission = (string) config('gestor.alerts.recipient_permission', 'ai.manage');

        try {
            // Deactivated users never receive alerts.
            $holders = User::permission($permission)->where('active', true)->get();
        } catch (PermissionDoesNotExist) {
            return new Collection;
        }

        if ($alert->company_id === null) {
            return $holders;
        }

        return $holders->filter(fn (User $user): bool => $user->company_id === $alert->company_id
            || $user->is_global
            || $user->can(CurrentCompany::VIEW_ALL_PERMISSION))->values();
    }
}
