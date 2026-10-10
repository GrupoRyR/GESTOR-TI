<?php

namespace App\Domain\Assistant;

use App\Services\Ai\RetryTransientStep;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Promptable;

/**
 * `/aprender` extraction (App\Domain\Assistant\AiDraftParser): one model
 * call, no tools, JSON out. The text is the person's own words, already
 * checked for secrets by App\Domain\Assistant\LearnDrafts.
 */
#[MaxSteps(1)]
final class LearnAgent implements Agent, HasMiddleware
{
    use Promptable;

    public function instructions(): string
    {
        $types = [];
        foreach (KnowledgeTypes::FIELDS as $type => $fields) {
            $types[] = "- {$type}: ".implode(', ', array_map(
                fn (string $key, array $field) => "{$key} ({$field[0]})",
                array_keys($fields),
                $fields,
            ));
        }

        return "Convierte lo que una persona de TI quiere registrar en un borrador de ficha de la base de conocimiento.\n"
            ."Responde SOLO un objeto JSON, sin texto alrededor, con estas claves:\n"
            .'type (uno de: '.implode(', ', KnowledgeTypes::TYPES).'), title (máximo 80 caracteres), '
            .'companies (lista de nombres de empresa mencionados, vacía si no menciona ninguna), '
            .'area (área mencionada o null) y fields (solo los campos del tipo que el texto dice; '
            ."steps es una lista de pasos).\n"
            ."Campos por tipo:\n".implode("\n", $types)."\n"
            .'No inventes nada: lo que el texto no dice no va. Nunca incluyas contraseñas ni llaves.';
    }

    public function middleware(): array
    {
        return [new RetryTransientStep];
    }
}
