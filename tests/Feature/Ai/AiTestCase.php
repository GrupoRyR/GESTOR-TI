<?php

namespace Tests\Feature\Ai;

use App\Enums\Ai\RequestStatus;
use App\Models\AiRequest;
use App\Models\Area;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Shared Gestor IA fixture: three companies (never assume two), the real
 * roles of config/permissions.php and the people of the design fixtures.
 */
abstract class AiTestCase extends TestCase
{
    use RefreshDatabase;

    protected Company $segurex;

    protected Company $derivados;

    protected Company $third;

    protected Area $ventas;

    protected Area $compras;

    protected Area $derivadosVentas;

    /** Líder IA (ai.manage, global). */
    protected User $jhon;

    /** Aprobador de proyectos. */
    protected User $edward;

    /** Súper Admin without the project approver role. */
    protected User $superAdmin;

    /** Usuario final of Segurex · Ventas. */
    protected User $paola;

    /** Usuario final of Segurex · Ventas. */
    protected User $juan;

    /** Usuario final of Derivados · Ventas. */
    protected User $derivadosUser;

    /** Aprobador de área of Segurex · Ventas. */
    protected User $ventasApprover;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->segurex = Company::factory()->create(['name' => 'Segurex Latam', 'slug' => 'segurex']);
        $this->derivados = Company::factory()->create(['name' => 'Soluciones y Derivados', 'slug' => 'derivados']);
        $this->third = Company::factory()->create(['name' => 'Tercera', 'slug' => 'tercera']);

        $this->ventas = Area::factory()->for($this->segurex)->create(['name' => 'Ventas', 'slug' => 'ventas']);
        $this->compras = Area::factory()->for($this->segurex)->create(['name' => 'Compras', 'slug' => 'compras']);
        $this->derivadosVentas = Area::factory()->for($this->derivados)->create(['name' => 'Ventas', 'slug' => 'ventas']);

        $this->jhon = $this->user($this->segurex, 'Líder IA', 'Jhon Castro');
        $this->edward = $this->user($this->segurex, 'Aprobador de proyectos', 'Edward Ramanan');
        $this->superAdmin = $this->user($this->segurex, 'Súper Admin', 'Leonardo Herrera');
        $this->paola = $this->user($this->segurex, 'Usuario final', 'Paola Ríos', $this->ventas);
        $this->juan = $this->user($this->segurex, 'Usuario final', 'Juan Pérez', $this->ventas);
        $this->derivadosUser = $this->user($this->derivados, 'Usuario final', 'Diego Restrepo', $this->derivadosVentas);
        $this->ventasApprover = $this->user($this->segurex, 'Aprobador de área', 'Gerente de ventas', $this->ventas);
        $this->ventas->forceFill(['approver_user_id' => $this->ventasApprover->id])->save();
    }

    protected function user(Company $company, string $role, string $name, ?Area $area = null): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->forceFill(['company_id' => $company->id])->save();
        $user->assignRole($role);

        if ($area !== null) {
            Employee::factory()->create([
                'company_id' => $company->id,
                'area_id' => $area->id,
                'user_id' => $user->id,
                'name' => $name,
            ]);
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function request(array $attributes = []): AiRequest
    {
        return AiRequest::factory()->create(array_replace([
            'company_id' => $this->segurex->id,
            'area_id' => $this->ventas->id,
            'requester_id' => $this->paola->id,
            'created_by_id' => $this->paola->id,
            'status' => RequestStatus::Received,
        ], $attributes));
    }
}
