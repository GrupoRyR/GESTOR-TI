<?php

namespace App\Http\Controllers\AI;

use App\Actions\Ai\RequestReapproval;
use App\Http\Controllers\Controller;
use App\Models\AiProject;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Pedir nueva aprobación" (`ai.projects.reapproval.store`, banner of the
 * project summary): App\Actions\Ai\RequestReapproval. Errors come back on
 * the `project` key.
 */
class ReapprovalController extends Controller
{
    public function store(Request $http, AiProject $project, RequestReapproval $reapproval): RedirectResponse
    {
        /** @var User $user */
        $user = $http->user();

        $rows = $reapproval->handle($project, $user);

        return back()->with('success', $rows->count() === 1
            ? 'Pedimos la nueva aprobación al aprobador.'
            : "Pedimos la nueva aprobación a {$rows->count()} aprobadores.");
    }
}
