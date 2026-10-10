<?php

namespace App\Http\Controllers\Assistant;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

/**
 * TEMPORARY (Stage C): AssistantLayout tabs whose pages later tasks build
 * (Excel import, /aprender drafts and unanswered questions in C2). They
 * exist so Ziggy never throws: they go back to the knowledge list with a
 * notice until the real controller replaces them.
 */
class StageCPlaceholderController extends Controller
{
    public const NOTICE = 'Disponible pronto.';

    public function __invoke(): RedirectResponse
    {
        return redirect()->route('assistant.knowledge.index')->with('error', self::NOTICE);
    }
}
