<?php

namespace App\Support\Vault;

/**
 * "Edge en Windows · 10.0.0.5": where a vault access came from, for the
 * access log (`VaultAccessLog.from`) and the Telegram notice. Browser and
 * system come from the user agent; unknown ones read "Navegador
 * desconocido".
 */
final class AccessOrigin
{
    /** Order matters: Edge and Opera also say "Chrome", Chrome also says "Safari". */
    private const BROWSERS = [
        'Edg/' => 'Edge',
        'EdgA/' => 'Edge',
        'OPR/' => 'Opera',
        'Firefox/' => 'Firefox',
        'FxiOS/' => 'Firefox',
        'CriOS/' => 'Chrome',
        'Chrome/' => 'Chrome',
        'Safari/' => 'Safari',
    ];

    private const SYSTEMS = [
        'Android' => 'Android',
        'iPhone' => 'iPhone',
        'iPad' => 'iPad',
        'Windows' => 'Windows',
        'Mac OS X' => 'macOS',
        'Linux' => 'Linux',
    ];

    public static function describe(?string $userAgent, ?string $ip = null): string
    {
        $agent = (string) $userAgent;
        $browser = self::first(self::BROWSERS, $agent);
        $system = self::first(self::SYSTEMS, $agent);

        $label = match (true) {
            $browser !== null && $system !== null => "{$browser} en {$system}",
            $browser !== null => $browser,
            $system !== null => "Navegador en {$system}",
            $agent === 'console' => 'Servidor',
            default => 'Navegador desconocido',
        };

        return $ip === null || $ip === '' ? $label : "{$label} · {$ip}";
    }

    /**
     * @param  array<string, string>  $map
     */
    private static function first(array $map, string $agent): ?string
    {
        foreach ($map as $needle => $label) {
            if (str_contains($agent, $needle)) {
                return $label;
            }
        }

        return null;
    }
}
