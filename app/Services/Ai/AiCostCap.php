<?php

namespace App\Services\Ai;

use App\Models\Alert;
use App\Models\User;
use App\Services\Alerts\AlertService;
use App\Support\Integrations\AiProviderSettings;
use Illuminate\Database\Eloquent\Collection;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Monthly AI spend cap (PROMPT 6.8.3): a warning alert when the month's
 * spend reaches `ai.cost_warning_percent` of the cap and a critical one
 * when it reaches the cap (the AI features turn off: `off_cap`). Each one
 * at most once per calendar month (Bogotá), to the people who can change
 * the cap in Administración › Integraciones (`settings.manage`: Súper
 * Admin, today Jhon and Leonardo).
 */
final class AiCostCap
{
    private const RECIPIENT_PERMISSION = 'settings.manage';

    public function __construct(
        private readonly AiProviderSettings $settings,
        private readonly AlertService $alerts,
    ) {}

    /**
     * After a call: the warning once the threshold is crossed.
     */
    public function check(): void
    {
        $cap = $this->settings->monthlyCap();
        if ($cap === null) {
            return;
        }

        $spend = $this->settings->monthSpend();

        if ($spend >= $cap) {
            $this->reached();
        } elseif ($spend >= $cap * $this->settings->warnRatio()) {
            $percent = (int) round($this->settings->warnRatio() * 100);
            $this->once('warning', 'warning', "Gasto de IA al {$percent} % del tope mensual", $spend, $cap);
        }
    }

    /**
     * The cap is reached: the AI features are off until next month.
     */
    public function reached(): void
    {
        $cap = $this->settings->monthlyCap();
        if ($cap !== null) {
            $this->once('reached', 'critical', 'Se alcanzó el tope mensual de gasto de IA: el asistente se apagó', $this->settings->monthSpend(), $cap);
        }
    }

    private function once(string $kind, string $level, string $title, float $spend, float $cap): void
    {
        $month = now(AiProviderSettings::TIMEZONE)->format('Y-m');
        $groupKey = "ai.cost_cap.{$kind}:{$month}";

        if (Alert::query()->where('group_key', $groupKey)->exists()) {
            return;
        }

        $this->alerts->raise(
            level: $level,
            source: 'ai',
            title: $title,
            body: 'Van '.self::usd($spend).' de '.self::usd($cap).' este mes. El tope se cambia en Administración › Integraciones.',
            groupKey: $groupKey,
            recipients: $this->recipients(),
        );
    }

    /**
     * @return Collection<int, User>
     */
    private function recipients(): Collection
    {
        try {
            return User::permission(self::RECIPIENT_PERMISSION)->where('active', true)->orderBy('id')->get();
        } catch (PermissionDoesNotExist) {
            return new Collection;
        }
    }

    /**
     * "US$ 20" / "US$ 20,50".
     */
    public static function usd(float $amount): string
    {
        $decimals = abs($amount - round($amount)) < 0.005 ? 0 : 2;

        return 'US$ '.number_format($amount, $decimals, ',', '.');
    }
}
