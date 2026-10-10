<?php

namespace App\Http\Controllers\AI;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

/**
 * TEMPORARY: Gestor IA routes the pages already link to but whose form is
 * not designed yet (product create/edit, incident create). They go back
 * with a notice so Ziggy never throws, until the design delivers them.
 */
class PendingDesignController extends Controller
{
    public const NOTICE = 'Disponible pronto.';

    public function __invoke(): RedirectResponse
    {
        return back(fallback: route('ai.products.index'))->with('error', self::NOTICE);
    }
}
