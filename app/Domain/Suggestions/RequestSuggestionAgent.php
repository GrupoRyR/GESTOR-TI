<?php

namespace App\Domain\Suggestions;

use App\Services\Ai\RetryTransientStep;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Promptable;

/**
 * AI suggestions of a request (Stage D6): one model call, no tools, JSON
 * out, only the keys of the kinds asked for (summary, criteria, size,
 * duplicates). Called only through App\Services\Ai\AiRuntime by
 * App\Domain\Suggestions\SuggestionGenerator; the input is the whitelist
 * of App\Domain\Suggestions\SuggestionInput.
 */
#[MaxSteps(1)]
final class RequestSuggestionAgent implements Agent, HasMiddleware
{
    use Promptable;

    /** One call for several kinds ("Sugerir con IA"). */
    public const FEATURE = 'suggestion.request';

    /** One kind again ("Volver a sugerir"): `suggestion.summary`, `.criteria`, `.size`, `.duplicates`. */
    public const FEATURE_PREFIX = 'suggestion.';

    private const KEYS = [
        'summary' => '"summary": resumen para TI en español claro, máximo 3 frases y 600 caracteres, sin inventar nada.',
        'criteria' => '"criteria": lista de 2 a 5 criterios de aceptación (texto, una frase cada uno) que quien pidió pueda comprobar al probar la entrega.',
        'size' => '"size": objeto {"size": "S" | "M" | "L" | "XL", "reason": una frase}. S = uno o dos días, M = una o dos semanas, L = un mes, XL = más de un mes.',
        'duplicate' => '"duplicates": lista (vacía si no hay) de las solicitudes de open_requests que piden lo mismo, máximo 3: {"key", "level": "alto" | "medio", '
            .'"reason_summary": máximo 8 palabras ("mismo problema y misma app"), "reasons": lista de frases, "differences": lo que solo pide esta solicitud}. Usa solo keys de open_requests.',
    ];

    /**
     * @param  list<string>  $kinds  summary|criteria|size|duplicate
     */
    public function __construct(private readonly array $kinds) {}

    public static function feature(array $kinds): string
    {
        if (count($kinds) !== 1) {
            return self::FEATURE;
        }

        return self::FEATURE_PREFIX.($kinds[0] === 'duplicate' ? 'duplicates' : $kinds[0]);
    }

    public function instructions(): string
    {
        $keys = array_map(fn (string $kind) => '- '.self::KEYS[$kind], array_values(array_intersect(array_keys(self::KEYS), $this->kinds)));

        return "Ayudas al equipo de TI del Grupo RYR a evaluar solicitudes internas de mejoras de software.\n"
            .'Recibes un JSON: "request" con title, problem, current_process, expected_result y app (un campo null no se envió)'
            .(in_array('duplicate', $this->kinds, true) ? ' y "open_requests" con key y title de otras solicitudes abiertas de la misma empresa' : '')
            .".\nResponde SOLO un objeto JSON, sin texto alrededor, con estas claves:\n".implode("\n", $keys)."\n"
            .'Escribe en español. No inventes datos. Nunca incluyas contraseñas, costos ni nombres de personas.';
    }

    public function middleware(): array
    {
        return [new RetryTransientStep];
    }
}
