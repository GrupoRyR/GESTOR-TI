<?php

namespace Tests\Feature\Ai;

use App\Models\AiRequest;
use App\Support\Tenancy\CurrentCompany;

class AiRequestCompanyScopeTest extends AiTestCase
{
    public function test_requests_are_isolated_per_company(): void
    {
        $segurex = $this->request();
        $derivados = $this->request(['company_id' => $this->derivados->id, 'area_id' => $this->derivadosVentas->id, 'requester_id' => $this->derivadosUser->id]);
        $third = $this->request(['company_id' => $this->third->id, 'area_id' => null]);

        $this->actingAs($this->paola);
        $this->assertSame([$segurex->key], AiRequest::query()->pluck('key')->all());

        $this->actingAs($this->derivadosUser);
        $this->assertSame([$derivados->key], AiRequest::query()->pluck('key')->all());

        // Líder IA has companies.view_all: "Todas" until a company is chosen.
        $this->actingAs($this->jhon);
        $this->assertSame([$segurex->key, $derivados->key, $third->key], AiRequest::query()->orderBy('id')->pluck('key')->all());

        app(CurrentCompany::class)->set($this->third->id);
        $this->assertSame([$third->key], AiRequest::query()->pluck('key')->all());
    }

    public function test_company_id_is_filled_from_the_context(): void
    {
        $request = app(CurrentCompany::class)->runAs($this->derivados->id, fn () => AiRequest::factory()->create([
            'company_id' => null,
            'area_id' => $this->derivadosVentas->id,
        ]));

        $this->assertSame($this->derivados->id, $request->company_id);
    }
}
