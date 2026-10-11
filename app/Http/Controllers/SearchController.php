<?php

namespace App\Http\Controllers;

use App\Models\AiProduct;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Top-bar search (`search`, GET `q`). Users of the Gestor IA (module
 * active, AiProductPolicy::viewAny) go to its search (`ai.search`: docs,
 * requests and products); people who only see Colaboradores go to that
 * list; everybody else goes back with a notice.
 */
class SearchController extends Controller
{
    public const NOTICE = 'No tienes dónde buscar todavía.';

    public function __invoke(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $query = trim($request->string('q')->toString());
        $parameters = $query === '' ? [] : ['q' => $query];

        if ((bool) config('modules.ai.active', false) && $user->can('viewAny', AiProduct::class)) {
            return redirect()->route('ai.search', $parameters);
        }

        if ($user->can('employees.view')) {
            return redirect()->route('employees.index', $parameters);
        }

        return redirect()->back(fallback: route('home'))->with('error', self::NOTICE);
    }
}
