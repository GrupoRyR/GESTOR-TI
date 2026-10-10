<?php

namespace App\Telegram\Commands;

use App\Actions\Ai\LogTime;
use App\Domain\Ai\ProjectCosts;
use App\Domain\Ai\Week;
use App\Models\AiProject;
use App\Models\AiRequest;
use App\Models\AiTimeEntry;
use App\Models\TelegramLink;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

/**
 * Telegram `/horas GIA-123 2 conexión con SAP` (PROMPT 6.4.11): the key of
 * a request that became a project (or the project key P-n), the hours
 * (`2`, `1,5`, `1.5`, `2h`) and what was done. Today's date (Bogotá),
 * origin `telegram`, through LogTime (AiProjectPolicy::logTime). Returns
 * the reply in Spanish; every error explains how to fix it.
 */
final class HoursCommand
{
    public const COMMAND = '/horas';

    public const USAGE = 'Para cargar horas escribe: /horas GIA-123 2 conexión con SAP (la solicitud o el proyecto, las horas y qué hiciste).';

    public const UNLINKED = 'Este chat no está vinculado a una cuenta del Gestor TI. Entra al Gestor TI, abre «Perfil y notificaciones» y pulsa «Vincular Telegram».';

    public function __construct(private readonly LogTime $log) {}

    public function handle(string $chatId, string $argument): string
    {
        $user = TelegramLink::findLinkedChat($chatId)?->user;

        if (! $user instanceof User || ! $user->active) {
            return self::UNLINKED;
        }

        $parts = preg_split('/\s+/u', trim($argument), 3) ?: [];

        if (count($parts) < 2 || $parts[0] === '') {
            return self::USAGE;
        }

        $key = mb_strtoupper($parts[0]);
        $hoursText = rtrim(mb_strtolower($parts[1]), 'h');
        $note = trim($parts[2] ?? '');

        if (preg_match('/^\d{1,2}([.,]\d{1,2})?$/', $hoursText) !== 1) {
            return 'No entendí cuántas horas: escribe un número, por ejemplo 2 o 1,5. '.self::USAGE;
        }

        $hours = (float) str_replace(',', '.', $hoursText);

        if ($hours <= 0 || $hours > LogTime::MAX_HOURS) {
            return 'Cada registro lleva entre 0,1 y '.LogTime::MAX_HOURS.' horas. Si fueron varios días, cárgalos por separado.';
        }

        if ($note === '') {
            return "Cuéntame qué hiciste después de las horas, por ejemplo: /horas {$key} {$parts[1]} conexión con SAP.";
        }

        $request = AiRequest::query()->withoutGlobalScopes()->whereNull('deleted_at')->where('key', $key)->first();
        $project = $request !== null
            ? AiProject::query()->withoutGlobalScopes()->whereHas('requests', fn ($query) => $query->whereKey($request->id))->first()
            : AiProject::query()->withoutGlobalScopes()->where('key', $key)->first();

        if ($request === null && $project === null) {
            return "No encontré {$key}. Revisa la clave de la solicitud (GIA-123) o del proyecto (P-7).";
        }

        if ($project === null) {
            return "{$key} todavía no es un proyecto: las horas se cargan cuando TI lo convierte en proyecto.";
        }

        if (! Gate::forUser($user)->allows('logTime', $project)) {
            return "No puedes cargar horas en {$project->key}: no estás en su equipo.";
        }

        $today = Week::today();
        $this->log->handle($project, $user, null, $today->toDateString(), $hours, $note, 'telegram');

        $week = (float) AiTimeEntry::query()
            ->where('user_id', $user->id)
            ->whereDate('date', '>=', $today->startOfWeek(CarbonImmutable::MONDAY)->toDateString())
            ->whereDate('date', '<=', $today->toDateString())
            ->sum('hours');

        $origin = $request !== null ? " ({$request->key})" : '';

        return 'Listo: '.ProjectCosts::hours($hours)." en {$project->key} · {$project->name}{$origin}, hoy. Esta semana llevas ".ProjectCosts::hours($week).'.';
    }
}
