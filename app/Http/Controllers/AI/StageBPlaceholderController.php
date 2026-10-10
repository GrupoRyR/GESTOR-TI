<?php

namespace App\Http\Controllers\AI;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

/**
 * TEMPORARY (Stage B): Gestor IA routes the design pages already call but
 * later tasks implement (docs and value in B7, vault in B8, products in
 * B9, the weekly review). They exist so
 * Ziggy never throws: a page sends the user back to Inicio, an action goes
 * back where it came from, both with a notice, until the real controller
 * replaces them.
 */
class StageBPlaceholderController extends Controller
{
    public const NOTICE = 'Disponible en la etapa B.';

    public function __invoke(): RedirectResponse
    {
        // No injected Request: `{request}` is a route parameter of several placeholders.
        if (request()->isMethod('GET')) {
            return redirect()->route('home')->with('error', self::NOTICE);
        }

        return back(fallback: route('home'))->with('error', self::NOTICE);
    }
}
