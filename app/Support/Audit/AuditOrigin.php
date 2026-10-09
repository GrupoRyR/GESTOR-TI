<?php

namespace App\Support\Audit;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;

/**
 * Where an activity log entry came from, stamped on every new entry
 * (`properties.origin`, `properties.ip`) by the `creating` listener
 * registered in AppServiceProvider:
 *
 * - an explicit context (`runAs()`, e.g. `telegram` for webhook updates),
 *   kept as hidden Context so it also travels into queued jobs;
 * - otherwise `api` for routed `/api/*` requests, `web` for any other routed
 *   HTTP request;
 * - otherwise `system` (console commands, scheduler, queue workers).
 *
 * The IP is the request IP for `web`/`api` only. Values already present in
 * the properties (e.g. the emergency login logs its own `ip`) are kept.
 */
class AuditOrigin
{
    public const WEB = 'web';

    public const API = 'api';

    public const TELEGRAM = 'telegram';

    public const SYSTEM = 'system';

    public const ALL = [self::WEB, self::TELEGRAM, self::SYSTEM, self::API];

    private const CONTEXT_KEY = 'audit_origin';

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function runAs(string $origin, Closure $callback): mixed
    {
        $previous = Context::getHidden(self::CONTEXT_KEY);
        Context::addHidden(self::CONTEXT_KEY, $origin);

        try {
            return $callback();
        } finally {
            $previous === null
                ? Context::forgetHidden(self::CONTEXT_KEY)
                : Context::addHidden(self::CONTEXT_KEY, $previous);
        }
    }

    public static function current(): string
    {
        $explicit = Context::getHidden(self::CONTEXT_KEY);

        if (is_string($explicit) && in_array($explicit, self::ALL, true)) {
            return $explicit;
        }

        $request = self::routedRequest();

        if ($request === null) {
            return self::SYSTEM;
        }

        return $request->is('api/*') ? self::API : self::WEB;
    }

    public static function ip(string $origin): ?string
    {
        if (! in_array($origin, [self::WEB, self::API], true)) {
            return null;
        }

        return self::routedRequest()?->ip();
    }

    /**
     * Adds `origin` and `ip` to a new activity's properties.
     */
    public static function stamp(Model $activity): void
    {
        /** @var Collection<string, mixed> $properties */
        $properties = collect($activity->getAttribute('properties') ?? []);
        $origin = $properties->get('origin');

        if (! is_string($origin) || ! in_array($origin, self::ALL, true)) {
            $origin = self::current();
            $properties->put('origin', $origin);
        }

        if (! $properties->has('ip')) {
            $properties->put('ip', self::ip($origin));
        }

        $activity->setAttribute('properties', $properties);
    }

    private static function routedRequest(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = app('request');

        return $request instanceof Request && $request->route() !== null ? $request : null;
    }
}
