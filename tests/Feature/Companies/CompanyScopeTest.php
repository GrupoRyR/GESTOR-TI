<?php

namespace Tests\Feature\Companies;

use App\Models\Area;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CompanyScopeTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private Company $derivados;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::create(['name' => 'companies.view_all']);

        $this->segurex = Company::factory()->create(['slug' => 'segurex']);
        $this->derivados = Company::factory()->create(['slug' => 'derivados']);

        Area::factory()->count(2)->for($this->segurex)->create();
        Area::factory()->count(3)->for($this->derivados)->create();
    }

    public function test_without_user_the_scope_does_not_filter(): void
    {
        $this->assertSame(5, Area::count());
        $this->assertTrue(app(CurrentCompany::class)->isAll());
    }

    public function test_user_without_view_all_only_sees_own_company(): void
    {
        $user = User::factory()->create(['company_id' => $this->segurex->id]);
        $this->actingAs($user);

        $this->assertSame($this->segurex->id, app(CurrentCompany::class)->id());
        $this->assertSame(2, Area::count());
        $this->assertSame([$this->segurex->id], Area::pluck('company_id')->unique()->values()->all());
    }

    public function test_user_without_view_all_cannot_switch_through_session(): void
    {
        $user = User::factory()->create(['company_id' => $this->segurex->id]);
        $this->actingAs($user);
        session(['current_company_id' => $this->derivados->id]);

        $this->assertSame($this->segurex->id, app(CurrentCompany::class)->id());
        $this->assertSame(2, Area::count());
    }

    public function test_user_without_view_all_and_without_company_sees_nothing(): void
    {
        $this->actingAs(User::factory()->create(['company_id' => null]));

        $this->assertFalse(app(CurrentCompany::class)->isAll());
        $this->assertSame(0, Area::count());
    }

    public function test_user_with_view_all_sees_everything_until_a_company_is_chosen(): void
    {
        $user = User::factory()->create(['company_id' => $this->segurex->id]);
        $user->givePermissionTo('companies.view_all');
        $this->actingAs($user);

        $current = app(CurrentCompany::class);
        $this->assertTrue($current->isAll());
        $this->assertSame(5, Area::count());

        $current->set($this->derivados->id);

        $this->assertSame($this->derivados->id, $current->id());
        $this->assertSame(3, Area::count());

        $current->set(null);
        $this->assertSame(5, Area::count());
    }

    public function test_run_as_filters_and_restores_the_previous_context(): void
    {
        $current = app(CurrentCompany::class);

        $count = $current->runAs($this->derivados->id, fn () => Area::count());
        $this->assertSame(3, $count);

        $this->assertTrue($current->isAll());
        $this->assertSame(5, Area::count());

        $user = User::factory()->create(['company_id' => $this->segurex->id]);
        $this->actingAs($user);

        $this->assertSame(5, $current->runAs(null, fn () => Area::count()));
        $this->assertSame(2, Area::count());
    }

    public function test_for_user_resolves_the_context_of_any_user(): void
    {
        $plain = User::factory()->create(['company_id' => $this->derivados->id]);
        $global = User::factory()->create(['company_id' => $this->segurex->id]);
        $global->givePermissionTo('companies.view_all');

        $current = app(CurrentCompany::class);

        $this->assertSame($this->derivados->id, $current->forUser($plain));
        $this->assertNull($current->forUser($global));
    }

    public function test_creating_an_area_inside_a_context_fills_company_id(): void
    {
        $user = User::factory()->create(['company_id' => $this->derivados->id]);
        $this->actingAs($user);

        $area = Area::create(['name' => 'Calidad', 'slug' => 'calidad']);
        $this->assertSame($this->derivados->id, $area->company_id);

        $fromJob = app(CurrentCompany::class)->runAs(
            $this->segurex->id,
            fn () => Area::create(['name' => 'Logística', 'slug' => 'logistica']),
        );
        $this->assertSame($this->segurex->id, $fromJob->company_id);
    }

    public function test_an_explicit_company_id_is_kept(): void
    {
        $this->actingAs(User::factory()->create(['company_id' => $this->derivados->id]));

        $area = Area::create(['company_id' => $this->segurex->id, 'name' => 'Calidad', 'slug' => 'calidad']);

        $this->assertSame($this->segurex->id, $area->company_id);
    }
}
