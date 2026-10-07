<?php

namespace Tests\Feature\Companies;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CompanySwitchTest extends TestCase
{
    use RefreshDatabase;

    private Company $segurex;

    private Company $derivados;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::create(['name' => 'companies.view_all']);

        $this->segurex = Company::factory()->create();
        $this->derivados = Company::factory()->create();
    }

    private function globalUser(): User
    {
        $user = User::factory()->create(['company_id' => $this->segurex->id]);
        $user->givePermissionTo('companies.view_all');

        return $user;
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->post(route('company.switch'), ['company_id' => $this->derivados->id])
            ->assertRedirect(route('login'));
    }

    public function test_user_without_view_all_gets_403(): void
    {
        $user = User::factory()->create(['company_id' => $this->segurex->id]);

        $this->actingAs($user)
            ->post(route('company.switch'), ['company_id' => $this->derivados->id])
            ->assertForbidden();

        $this->assertNull(session('current_company_id'));
    }

    public function test_switch_to_a_company_stores_it_and_shares_it(): void
    {
        $this->actingAs($this->globalUser())
            ->from(route('dashboard'))
            ->post(route('company.switch'), ['company_id' => $this->derivados->id])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('current_company_id', $this->derivados->id);

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('current_company_id', $this->derivados->id));
    }

    public function test_switch_to_all_stores_null(): void
    {
        $this->actingAs($this->globalUser())
            ->withSession(['current_company_id' => $this->derivados->id])
            ->post(route('company.switch'), ['company_id' => null])
            ->assertRedirect();

        $this->assertNull(session('current_company_id'));

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('current_company_id', null));
    }

    public function test_inactive_company_is_rejected(): void
    {
        $inactive = Company::factory()->inactive()->create();

        $this->actingAs($this->globalUser())
            ->postJson(route('company.switch'), ['company_id' => $inactive->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('company_id');

        $this->assertNull(session('current_company_id'));
    }

    public function test_nonexistent_company_is_rejected(): void
    {
        $this->actingAs($this->globalUser())
            ->postJson(route('company.switch'), ['company_id' => 999999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('company_id');
    }

    public function test_company_id_must_be_present(): void
    {
        $this->actingAs($this->globalUser())
            ->postJson(route('company.switch'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('company_id');
    }
}
