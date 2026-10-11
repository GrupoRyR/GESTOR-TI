<?php

namespace App\Services\Ai;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\PendingStep;
use Throwable;

/**
 * Step middleware of the Laravel AI SDK (PROMPT 6.8.3): one retry of a
 * provider call that failed with 429, a 5xx or a connection error. Each
 * attempt is still reported (`StepFailed` / `StepCompleted`), so every call
 * gets its own `ai_interactions` row.
 */
final class RetryTransientStep
{
    public function handle(PendingStep $step, Closure $next): mixed
    {
        try {
            return $next($step);
        } catch (Throwable $exception) {
            if (! self::transient($exception)) {
                throw $exception;
            }

            return $next($step);
        }
    }

    public static function transient(Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof RateLimitedException
                || $current instanceof ProviderOverloadedException
                || $current instanceof ProviderConnectionException
                || $current instanceof ConnectionException) {
                return true;
            }

            if ($current instanceof RequestException && $current->response !== null) {
                $status = $current->response->status();

                return $status === 429 || $status >= 500;
            }
        }

        return false;
    }

    /**
     * HTTP status behind a failure, for the log (never the body).
     */
    public static function status(Throwable $exception): ?int
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof RequestException && $current->response !== null) {
                return $current->response->status();
            }
        }

        return null;
    }
}
