<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAreaRequest;
use App\Http\Requests\Admin\UpdateAreaRequest;
use App\Models\Area;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administración › Áreas. Listing and route binding honour `CompanyScope`,
 * so areas of other companies are invisible (404) to restricted users.
 */
class AreaController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('search')->trim()->value() ?: null;

        $areas = Area::query()
            ->with('company:id,short_name')
            ->when($search, fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
            ->orderBy('company_id')
            ->orderBy('name')
            ->get();

        return Inertia::render('Admin/Areas/Index', [
            'areas' => $areas->map(fn (Area $area) => [
                'id' => $area->id,
                'company_id' => $area->company_id,
                'company_short_name' => $area->company?->short_name,
                'name' => $area->name,
                'slug' => $area->slug,
                'is_active' => $area->is_active,
            ])->values()->all(),
            'filters' => [
                'search' => $search,
            ],
        ]);
    }

    public function store(StoreAreaRequest $request): RedirectResponse
    {
        Area::create([
            'company_id' => $request->companyId(),
            'name' => $request->validated('name'),
            'slug' => $request->slug(),
            'is_active' => $request->boolean('is_active', true),
        ]);

        return back()->with('success', 'Área creada.');
    }

    public function update(UpdateAreaRequest $request, Area $area): RedirectResponse
    {
        $area->update([
            'name' => $request->validated('name'),
            'slug' => $request->slug(),
            'is_active' => $request->boolean('is_active'),
        ]);

        return back()->with('success', 'Área actualizada.');
    }
}
