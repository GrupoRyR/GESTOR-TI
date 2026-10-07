<?php

namespace App\Http\Controllers;

use App\Http\Requests\SwitchCompanyRequest;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;

/**
 * Company selector of the top bar (only for `companies.view_all`).
 */
class CompanySwitchController extends Controller
{
    public function __invoke(SwitchCompanyRequest $request, CurrentCompany $currentCompany): RedirectResponse
    {
        $currentCompany->set($request->companyId());

        return back();
    }
}
