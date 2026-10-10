<?php

namespace App\Domain\Suggestions;

use App\Services\Ai\RetryTransientStep;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Promptable;

/**
 * Draft of the user notes of a GitHub release (Stage D6, `release_notes`):
 * one model call, no tools, JSON out. IT always edits and confirms the
 * text on `Ai/Releases/Show`; requesters only receive the confirmed notes.
 */
#[MaxSteps(1)]
final class ReleaseNotesAgent implements Agent, HasMiddleware
{
    use Promptable;

    public const FEATURE = 'suggestion.release_notes';

    public function instructions(): string
    {
        return "Escribes las notas de una versión para las personas que usan la app (no son de TI).\n"
            .'Recibes un JSON con product, tag, requests (key y title de lo que pidieron) y technical_notes (las notas del release en GitHub).'
            ."\nResponde SOLO un objeto JSON, sin texto alrededor, con estas claves:\n"
            ."- \"title\": máximo 60 caracteres, qué cambió.\n"
            ."- \"body\": Markdown corto en español, uno o dos párrafos, qué puede hacer ahora quien usa la app, sin palabras técnicas, sin números de PR ni nombres de ramas.\n"
            .'No inventes cambios que no estén en los datos. Nunca incluyas contraseñas, costos ni nombres de personas.';
    }

    public function middleware(): array
    {
        return [new RetryTransientStep];
    }
}
