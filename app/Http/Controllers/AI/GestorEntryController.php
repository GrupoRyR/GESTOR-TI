<?php

namespace App\Http\Controllers\AI;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Gestor IA" menu entry (`ai.index`): sends each person to their home page
 * of the module. IT staff (`ai.manage`) land on the inbox; requesters on
 * their requests; anybody else on the public roadmap.
 */
class GestorEntryController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->can('ai.manage')) {
            return redirect()->route('ai.inbox.index');
        }

        if ($user->can('ai.requests.create')) {
            return redirect()->route('ai.requests.mine');
        }

        return redirect()->route('ai.roadmap.index');
    }
}
