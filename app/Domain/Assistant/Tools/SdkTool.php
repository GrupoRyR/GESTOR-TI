<?php

namespace App\Domain\Assistant\Tools;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * An App\Domain\Assistant\Tools\AssistantTool as a Laravel AI SDK tool,
 * bound to the asking person and the question's ToolContext:
 *
 * 1. the arguments are validated against the tool schema (invalid ones go
 *    back to the model as "Argumentos inválidos: …" and the tool does not run);
 * 2. the tool runs;
 * 3. its `data` passes the PrivacyFilter and goes to the provider as JSON.
 *
 * A tool failure is logged and answered "La herramienta falló." so one bad
 * record never breaks the whole answer.
 */
final class SdkTool implements Tool
{
    public function __construct(
        private readonly AssistantTool $tool,
        private readonly User $user,
        private readonly ToolContext $context,
    ) {}

    public function name(): string
    {
        return $this->tool->name();
    }

    public function description(): Stringable|string
    {
        return $this->tool->description();
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->tool->schema($schema);
    }

    public function handle(Request $request): Stringable|string
    {
        $arguments = $request->all();
        $errors = SchemaValidator::errors($arguments, self::jsonSchema($this->tool));

        if ($errors !== []) {
            return self::encode(['error' => 'Argumentos inválidos: '.implode(' ', $errors)]);
        }

        try {
            $result = $this->tool->handle($this->user, $arguments, $this->context);
        } catch (Throwable $exception) {
            Log::warning('Assistant tool failed', ['tool' => $this->tool->name(), 'error' => $exception::class.': '.$exception->getMessage()]);

            return self::encode(['error' => 'La herramienta falló.']);
        }

        $this->context->absorb($result);

        return self::encode(PrivacyFilter::clean($result->data));
    }

    /**
     * The object schema the provider receives for the tool.
     *
     * @return array<string, mixed>
     */
    public static function jsonSchema(AssistantTool $tool): array
    {
        $properties = $tool->schema(new JsonSchemaTypeFactory);

        return $properties === [] ? ['type' => 'object', 'properties' => []] : (new ObjectSchema($properties))->toSchema();
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function encode(array $data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
