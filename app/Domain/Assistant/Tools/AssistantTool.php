<?php

namespace App\Domain\Assistant\Tools;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/**
 * A read-only tool of the Asistente TI (PROMPT 6.8.2, 6.8.4). Registered in
 * config/gestor.php `assistant.tools`; the engine wraps it for the Laravel
 * AI SDK (App\Domain\Assistant\Tools\SdkTool), which:
 *
 * - validates the arguments against `schema()` before `handle()` runs
 *   (App\Domain\Assistant\Tools\SchemaValidator);
 * - passes the result's `data` through App\Domain\Assistant\Tools\PrivacyFilter
 *   before it reaches the AI provider.
 *
 * Tools only read, and only what `$user` may see. `name()` is what the
 * model calls (Spanish, snake_case); `description()` tells it when to.
 */
interface AssistantTool
{
    public function name(): string;

    public function description(): string;

    /**
     * Argument definitions (Laravel JSON schema types).
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array;

    /**
     * @param  array<string, mixed>  $arguments  already valid for `schema()`
     */
    public function handle(User $user, array $arguments, ToolContext $context): ToolResult;
}
