<?php

namespace App\Domain\Assistant;

use App\Models\User;
use Illuminate\Container\Attributes\Bind;

/**
 * Turns the text after `/aprender` into a proposed card. AppServiceProvider
 * binds App\Domain\Assistant\AiDraftParser (C3, AI provider) while the
 * provider is ready, else HeuristicDraftParser (C2, no AI); the attribute
 * below is the fallback binding.
 *
 * `fields` holds only the type fields the text gave; `origins` says, for a
 * type field key, `company`, `area` or `owner`, whether the value came
 * from the message (`message`) or was assumed (`assumed`, e.g. the
 * author's company).
 */
#[Bind(HeuristicDraftParser::class)]
interface DraftParser
{
    /**
     * @return array{
     *     type: string,
     *     title: string,
     *     description: string,
     *     company_ids: list<int>,
     *     area_id: ?int,
     *     owner_id: ?int,
     *     visibility: string,
     *     fields: array<string, string|list<string>>,
     *     origins: array<string, string>,
     * }
     */
    public function parse(string $text, User $author): array;
}
