<?php

namespace App\Domain\Assistant\Tools;

use App\Models\User;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * The assistant's tool catalog (config/gestor.php `assistant.tools`), in
 * its configured order: that order is part of the request prefix the
 * provider caches, so it never depends on the person or the question.
 */
final class ToolCatalog
{
    public function __construct(private readonly Container $container) {}

    /**
     * @return list<AssistantTool>
     */
    public function all(): array
    {
        return array_map(function (string $class): AssistantTool {
            $tool = $this->container->make($class);

            if (! $tool instanceof AssistantTool) {
                throw new InvalidArgumentException("[{$class}] is not an assistant tool.");
            }

            return $tool;
        }, array_values((array) config('gestor.assistant.tools', [])));
    }

    /**
     * SDK tools bound to the asking person and this question's context.
     *
     * @return list<SdkTool>
     */
    public function forRun(User $user, ToolContext $context): array
    {
        return array_map(fn (AssistantTool $tool) => new SdkTool($tool, $user, $context), $this->all());
    }
}
