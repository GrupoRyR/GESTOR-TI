<?php

namespace App\Telegram;

use App\Telegram\Contracts\CallbackHandler;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Maps `callback_data` prefixes to handlers. Data has the form
 * `{prefix}:{payload}` (Telegram limit: 64 bytes in total).
 *
 * Handlers come from config/gestor.php `telegram.callbacks` and from
 * `register()` (e.g. in a service provider). Singleton.
 */
class CallbackRegistry
{
    public const SEPARATOR = ':';

    /**
     * @var array<string, class-string<CallbackHandler>>
     */
    private array $handlers = [];

    public function __construct(private readonly Container $container)
    {
        foreach ((array) config('gestor.telegram.callbacks', []) as $prefix => $handler) {
            $this->register((string) $prefix, $handler);
        }
    }

    /**
     * @param  class-string<CallbackHandler>  $handler
     */
    public function register(string $prefix, string $handler): void
    {
        if (preg_match('/^[a-z0-9_.-]{1,32}$/', $prefix) !== 1) {
            throw new InvalidArgumentException("Invalid Telegram callback prefix [{$prefix}].");
        }

        if (! is_subclass_of($handler, CallbackHandler::class)) {
            throw new InvalidArgumentException("[{$handler}] must implement ".CallbackHandler::class.'.');
        }

        $this->handlers[$prefix] = $handler;
    }

    /**
     * Handler and payload for the callback data, or null when the prefix is
     * not registered.
     *
     * @return ?array{0: CallbackHandler, 1: string}
     */
    public function resolve(string $data): ?array
    {
        if (! str_contains($data, self::SEPARATOR)) {
            return null;
        }

        [$prefix, $payload] = explode(self::SEPARATOR, $data, 2);

        $handler = $this->handlers[$prefix] ?? null;

        return $handler === null ? null : [$this->container->make($handler), $payload];
    }

    /**
     * @return list<string>
     */
    public function prefixes(): array
    {
        return array_keys($this->handlers);
    }
}
