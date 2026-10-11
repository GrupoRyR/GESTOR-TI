<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns error responses into the design's `Errors/Error` Inertia page
 * (`{ status }`, resources/js/types/pages/errors.ts). Registered in
 * bootstrap/app.php through `withExceptions()->respond()`.
 *
 * Only for web requests and only when `gestor.error_pages` is on (null =
 * whenever `app.debug` is off, so local debugging keeps Laravel's pages).
 * Shared props are included when the web middleware already ran.
 */
class ErrorPage
{
    /** Statuses with a design page. */
    public const STATUSES = [403, 404, 419, 500, 503];

    public static function render(Response $response, Request $request): Response
    {
        $status = $response->getStatusCode();

        if (! self::applies($response, $request, $status)) {
            return $response;
        }

        return Inertia::render('Errors/Error', ['status' => $status])
            ->toResponse($request)
            ->setStatusCode($status);
    }

    private static function applies(Response $response, Request $request, int $status): bool
    {
        if (! in_array($status, self::STATUSES, true)) {
            return false;
        }

        if ($response instanceof JsonResponse || $request->is('api/*') || $request->expectsJson()) {
            return false;
        }

        return (bool) (config('gestor.error_pages') ?? ! config('app.debug'));
    }
}
