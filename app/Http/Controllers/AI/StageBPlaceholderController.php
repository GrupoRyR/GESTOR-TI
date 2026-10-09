<?php

namespace App\Http\Controllers\AI;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

/**
 * TEMPORARY (Stage B): Gestor IA routes the Stage A design already links to
 * (`ai.requests.*`, `ai.tasks.show`, `ai.requirements.show`,
 * `ai.vault.index`, `ai.ratings.create`, `ai.approvals.show`). They exist so
 * Ziggy never throws; each one sends the user back to Inicio with a notice
 * until Stage B replaces it with its real controller.
 */
class StageBPlaceholderController extends Controller
{
    public const NOTICE = 'Disponible en la etapa B.';

    public function __invoke(): RedirectResponse
    {
        return redirect()->route('home')->with('error', self::NOTICE);
    }
}
