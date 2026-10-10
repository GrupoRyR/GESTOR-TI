<?php

namespace App\Domain\GitHub;

use App\Domain\Ai\SecretGuard;
use App\Enums\Ai\CommentVisibility;
use App\Models\AiProject;
use App\Models\AiRequestComment;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Server side of lib/issue.ts ("Crear issue en GitHub"): the `gestor-ia`
 * label always goes first, the privacy footer always closes the body,
 * and a body that carries money, credentials or an internal note of the
 * project's requests is refused. The page builds the text; this only
 * re-checks it before it leaves for GitHub.
 */
final class IssueText
{
    /** Label every issue created from the Gestor carries. */
    public const LABEL = 'gestor-ia';

    public const FOOTER = 'No incluye costos ni notas internas.';

    public const TITLE_MAX = 256;

    public const MONEY_MESSAGE = 'El issue no puede incluir costos, tarifas ni valores en dinero.';

    public const INTERNAL_MESSAGE = 'El issue no puede incluir notas internas de la solicitud.';

    /** Amounts with a currency: "$ 1.200.000", "COP 450000", "USD 30". */
    private const MONEY = '/(?:\$\s?\d|\b(?:COP|USD)\s?\$?\s?\d)/i';

    /** Internal notes shorter than this are not matched (common words). */
    private const NOTE_MIN_LENGTH = 15;

    public function __construct(private readonly SecretGuard $secrets) {}

    /**
     * `gestor-ia` first, then the given labels trimmed, without repeats.
     *
     * @param  list<string>  $labels
     * @return list<string>
     */
    public static function labels(array $labels): array
    {
        $result = [self::LABEL];

        foreach ($labels as $label) {
            $label = trim((string) $label);

            if ($label !== '' && ! in_array(Str::lower($label), array_map(Str::lower(...), $result), true)) {
                $result[] = $label;
            }
        }

        return $result;
    }

    /**
     * The body with the privacy footer (added when the page left it out).
     */
    public static function withFooter(string $body): string
    {
        $body = rtrim($body);

        return (str_contains($body, self::FOOTER) ? $body : $body."\n\n---\n\n".self::FOOTER)."\n";
    }

    /**
     * @throws ValidationException on `body` (or `title`)
     */
    public function assertSafe(string $title, string $body, AiProject $project): void
    {
        $this->secrets->assertClean($title, 'title');
        $this->secrets->assertClean($body, 'body');

        if (preg_match(self::MONEY, $title) === 1) {
            throw ValidationException::withMessages(['title' => self::MONEY_MESSAGE]);
        }

        if (preg_match(self::MONEY, $body) === 1) {
            throw ValidationException::withMessages(['body' => self::MONEY_MESSAGE]);
        }

        $haystack = Str::lower($title."\n".$body);

        $notes = AiRequestComment::query()
            ->whereIn('ai_request_id', $project->requests()->select('ai_requests.id'))
            ->where('visibility', CommentVisibility::Internal->value)
            ->pluck('body');

        foreach ($notes as $note) {
            $note = Str::lower(trim((string) $note));

            if (mb_strlen($note) >= self::NOTE_MIN_LENGTH && str_contains($haystack, $note)) {
                throw ValidationException::withMessages(['body' => self::INTERNAL_MESSAGE]);
            }
        }
    }
}
