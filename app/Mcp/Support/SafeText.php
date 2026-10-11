<?php

namespace App\Mcp\Support;

use App\Domain\Ai\Brief\BriefText;
use App\Domain\Ai\SecretGuard;
use App\Enums\Ai\CommentVisibility;
use App\Models\AiRequestComment;
use App\Models\User;

/**
 * Free text the connector returns (problem, criteria, comments, document
 * bodies…). A text is replaced whole by OMITTED, never partially masked:
 *
 * - always when it looks like a credential (SecretGuard);
 * - always when it carries a money amount (user rule of Stage E: costs
 *   never leave through the connector, even with `ai.costs.view`);
 * - for viewers without `ai.costs.view`, or who may not read the internal
 *   notes, through the "Brief para Claude" rules (BriefText): money
 *   amounts, and quotes of internal notes they may not read.
 */
final class SafeText
{
    public const OMITTED = '[Texto omitido: parece contener una credencial o un dato reservado. Consúltalo en el Gestor TI.]';

    /** Same money rule as App\Domain\Ai\Brief\BriefText ("$ 1.200", "COP 5", "USD 3"). */
    private const MONEY = '/(?:\$\s?\d|\b(?:COP|USD)\s?\$?\s?\d)/i';

    private function __construct(
        private readonly SecretGuard $secrets,
        private readonly ?BriefText $strict,
    ) {}

    /**
     * @param  list<int>  $requestIds  requests whose internal notes the text may quote
     */
    public static function for(User $viewer, bool $seesInternalNotes, array $requestIds): self
    {
        $secrets = app(SecretGuard::class);

        if ($seesInternalNotes && $viewer->can('ai.costs.view')) {
            return new self($secrets, null);
        }

        $notes = $seesInternalNotes || $requestIds === [] ? [] : AiRequestComment::query()
            ->whereIn('ai_request_id', $requestIds)
            ->where('visibility', CommentVisibility::Internal->value)
            ->pluck('body')
            ->all();

        return new self($secrets, new BriefText($secrets, $notes));
    }

    /**
     * The trimmed text, OMITTED when unsafe, null when empty.
     */
    public function clean(?string $text): ?string
    {
        $text = trim((string) $text);

        if ($text === '') {
            return null;
        }

        $safe = $this->strict !== null
            ? $this->strict->safe($text)
            : $this->secrets->find($text) === null && preg_match(self::MONEY, $text) !== 1;

        return $safe ? $text : self::OMITTED;
    }
}
