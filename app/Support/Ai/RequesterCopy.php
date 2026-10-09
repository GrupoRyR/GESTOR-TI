<?php

namespace App\Support\Ai;

use App\Enums\Ai\AreaApprovalStatus;
use App\Enums\Ai\RequestStatus;
use App\Models\AiRequest;

/**
 * Plain-Spanish sentences the requester reads (design: "Dónde está", the
 * next step under the phase rail, roadmap lines). Server-written so every
 * page says the same thing.
 */
final class RequesterCopy
{
    /**
     * "Dónde está": label + explanation (RequesterShowProps `where`).
     *
     * @return array{label: string, explanation: string}
     */
    public static function where(AiRequest $request): array
    {
        [$label, $explanation] = match ($request->status) {
            RequestStatus::Received => $request->area_approval_status === AreaApprovalStatus::Pending
                ? ['Esperando el visto bueno de tu área', 'El aprobador de tu área la revisa antes de que TI la evalúe. Te avisamos cuando lo haga.']
                : ['TI la recibió', 'TI te da una primera respuesta en los próximos días hábiles, en la conversación de abajo.'],
            RequestStatus::Evaluating => ['TI la está evaluando', 'Mide cuánto tiempo ahorra y cuánto esfuerzo toma. Si necesita algo, te pregunta aquí.'],
            RequestStatus::NeedsInfo => ['TI necesita que le respondas', 'La evaluación sigue cuando contestes en la conversación de abajo.'],
            RequestStatus::PendingApproval => [
                'Esperando la aprobación de un aprobador de proyectos',
                'TI ya la evaluó y la dejó lista. Ahora un aprobador decide si entra a la hoja de ruta. No tienes que hacer nada; te avisamos.',
            ],
            RequestStatus::Approved => ['Aprobada', 'Empieza cuando se libere un cupo de construcción. Te avisamos cuando arranque.'],
            RequestStatus::Backlog => ['En la hoja de ruta', 'Espera su turno según la prioridad de tu área. Cada +1 la sube.'],
            RequestStatus::Rejected => ['No sigue adelante', $request->rejection_message ?? 'TI explicó el motivo en la conversación.'],
            RequestStatus::Planned => ['Planificada', 'Ya tiene lugar en el plan de TI. Te avisamos cuando empiece la construcción.'],
            RequestStatus::InDevelopment => ['En construcción', 'TI la está construyendo. Te avisamos cuando la puedas probar.'],
            RequestStatus::InTesting => ['Te toca validarla', 'Prueba la versión de pruebas y marca cada criterio.'],
            RequestStatus::InProduction => ['Ya está en producción', 'Ya la puedes usar. Cuéntanos cómo te fue.'],
            RequestStatus::Closed => ['Cerrada', 'Esta solicitud terminó.'],
        };

        return ['label' => $label, 'explanation' => $explanation];
    }

    /**
     * Sentence under the phase rail (Mis solicitudes, Inicio).
     */
    public static function nextStep(AiRequest $request, ?int $rank, bool $canRate): string
    {
        return match ($request->status) {
            RequestStatus::Received => $request->area_approval_status === AreaApprovalStatus::Pending
                ? 'Espera el visto bueno de tu área.'
                : 'TI la revisa y te responde en la conversación.',
            RequestStatus::Evaluating => 'TI la está evaluando.',
            RequestStatus::NeedsInfo => 'La evaluación sigue cuando respondas.',
            RequestStatus::PendingApproval => 'TI ya la evaluó. Falta el visto bueno de un aprobador.',
            RequestStatus::Approved => 'Aprobada. Empieza cuando se libere un cupo de construcción.',
            RequestStatus::Backlog => $rank !== null
                ? 'En backlog: '.lcfirst(self::rankLabel($request, $rank)).'.'
                : 'En backlog: espera su turno en la hoja de ruta.',
            RequestStatus::Rejected => 'No sigue adelante. Mira el motivo en la solicitud.',
            RequestStatus::Planned => 'Planificada: ya tiene lugar en el plan de TI.',
            RequestStatus::InDevelopment => 'En construcción.',
            RequestStatus::InTesting => 'En pruebas. Te toca validar los criterios.',
            RequestStatus::InProduction => $canRate ? 'En producción. Cuéntanos cómo te fue.' : 'En producción.',
            RequestStatus::Closed => 'Cerrada.',
        };
    }

    /**
     * "Puesto 6 en la hoja de ruta de Compras".
     */
    public static function rankLabel(AiRequest $request, int $rank): string
    {
        return "Puesto {$rank} en la hoja de ruta de ".($request->area?->name ?? 'tu área');
    }

    /**
     * RequesterShowProps `roadmap_position` (null when not queued).
     *
     * @return array{rank: int, label: string, detail: ?string}|null
     */
    public static function roadmapPosition(AiRequest $request, ?int $rank): ?array
    {
        if ($rank === null) {
            return null;
        }

        $area = $request->area?->name ?? 'tu área';
        $ahead = $rank - 1;
        $after = $ahead === 0
            ? "es la siguiente de {$area}."
            : 'empieza después de '.$ahead.($ahead === 1 ? ' solicitud.' : ' solicitudes.');

        return [
            'rank' => $rank,
            'label' => self::rankLabel($request, $rank),
            'detail' => $request->status === RequestStatus::PendingApproval ? 'Si se aprueba, '.$after : ucfirst($after),
        ];
    }

    /**
     * Second line of a roadmap card ("Puesto 3 en Compras", "Ventas la está
     * probando"); null lets the card show the planned start or just the area.
     */
    public static function roadmapNote(AiRequest $request, ?int $rank): ?string
    {
        $area = $request->area?->name ?? 'El área';

        return match ($request->status) {
            RequestStatus::InTesting => "{$area} la está probando",
            RequestStatus::Approved => 'Empieza cuando se libere un cupo',
            RequestStatus::PendingApproval, RequestStatus::Backlog => $rank === null ? null : "Puesto {$rank} en {$area}",
            default => null,
        };
    }
}
