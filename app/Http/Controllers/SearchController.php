<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Top-bar search (`search`, GET `q`). Stage A has a single searchable list,
 * so the query goes to Colaboradores for users who can see it; everybody
 * else goes back with a notice. Stage B replaces this with a global search.
 */
class SearchController extends Controller
{
    public const NOTICE = 'La búsqueda llega en la etapa B.';

    public function __invoke(Request $request): RedirectResponse
    {
        if (! $request->user()->can('employees.view')) {
            return redirect()->back(fallback: route('home'))->with('error', self::NOTICE);
        }

        $query = trim($request->string('q')->toString());

        return redirect()->route('employees.index', $query === '' ? [] : ['q' => $query]);
    }
}
