<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

/**
 * TEMPORARY: Administración tabs whose page is not wired yet
 * (`admin.users.index` until C6, `admin.integrations.index` until C7).
 * They exist so the AdminLayout tabs (Ziggy) never throw; each one goes back
 * to Empresas with a notice.
 */
class AdminTabPlaceholderController extends Controller
{
    public const NOTICE = 'Disponible pronto.';

    public function __invoke(): RedirectResponse
    {
        return redirect()->route('admin.companies.index')->with('error', self::NOTICE);
    }
}
