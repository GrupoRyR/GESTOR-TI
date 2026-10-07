<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EmployeeAccessRequest;
use App\Models\Employee;
use App\Models\EmployeeAccess;
use App\Services\Credentials\EmployeeCredentialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Accesses of an employee. Routes use scoped bindings: the access must
 * belong to the employee, and the employee must be visible (CompanyScope).
 * The password is write-only except through `reveal`.
 */
class EmployeeAccessController extends Controller
{
    /** Seconds the UI shows a revealed value. */
    public const REVEAL_SECONDS = 30;

    public function store(EmployeeAccessRequest $request, Employee $employee): RedirectResponse
    {
        $employee->accesses()->create($request->accessAttributes());

        return back()->with('success', 'Acceso agregado.');
    }

    public function update(EmployeeAccessRequest $request, Employee $employee, EmployeeAccess $access): RedirectResponse
    {
        $access->update($request->accessAttributes());

        return back()->with('success', 'Acceso actualizado.');
    }

    public function destroy(Employee $employee, EmployeeAccess $access): RedirectResponse
    {
        $access->delete();

        return back()->with('success', 'Acceso eliminado.');
    }

    /**
     * The only JSON endpoint: returns the plain password once, never cached.
     */
    public function reveal(Request $request, EmployeeCredentialService $credentials, Employee $employee, EmployeeAccess $access): JsonResponse
    {
        $access->setRelation('employee', $employee);

        $value = $credentials->reveal($request->user(), $access, 'web');

        return response()
            ->json(['value' => $value, 'expires_in' => self::REVEAL_SECONDS])
            ->header('Cache-Control', 'no-store, private, max-age=0')
            ->header('Pragma', 'no-cache');
    }
}
