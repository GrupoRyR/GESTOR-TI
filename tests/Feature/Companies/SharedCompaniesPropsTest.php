<?php

namespace Tests\Feature\Companies;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SharedCompaniesPropsTest extends TestCase
{
    use RefreshDatabase;

    private Company $first;

    private Company $second;

    private Company $third;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::create(['name' => 'companies.view_all']);

        // Three companies on purpose: nothing may assume exactly two.
        $this->first = Company::factory()->create(['name' => 'Alfa S.A.S.', 'short_name' => 'Alfa']);
        $this->second = Company::factory()->create(['name' => 'Beta S.A.S.', 'short_name' => 'Beta']);
        $this->third = Company::factory()->create(['name' => 'Gamma S.A.S.', 'short_name' => 'Gamma']);
        Company::factory()->inactive()->create(['name' => 'Inactiva S.A.S.']);
    }

    public function test_global_user_receives_every_active_company_with_exact_shape(): void
    {
        $this->first->update([
            'slug' => 'alfa',
            'logo_path' => 'logos/alfa.png',
            'logo_dark_path' => null,
            'colors' => ['primary' => '#FF671F', 'accent' => '#666666', 'dark' => '#333333'],
        ]);

        $user = User::factory()->create(['company_id' => $this->first->id]);
        $user->givePermissionTo('companies.view_all');

        $this->actingAs($user)
            ->get(route('home'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('companies', 3)
                ->where('current_company_id', null)
                ->has('companies.0', fn (Assert $company) => $company
                    ->where('id', $this->first->id)
                    ->where('slug', 'alfa')
                    ->where('name', 'Alfa S.A.S.')
                    ->where('short_name', 'Alfa')
                    ->where('logo_url', fn (?string $url) => $url !== null && str_ends_with($url, '/storage/logos/alfa.png'))
                    ->where('logo_dark_url', null)
                    ->has('colors', fn (Assert $colors) => $colors
                        ->where('primary', '#FF671F')
                        ->where('accent', '#666666')
                        ->where('dark', '#333333')
                    )
                )
                ->where('companies.1.id', $this->second->id)
                ->where('companies.2.id', $this->third->id)
            );
    }

    public function test_missing_brand_colors_fall_back_to_the_configured_defaults(): void
    {
        config(['gestor.brand.default_colors' => ['primary' => '#111111', 'accent' => '#222222', 'dark' => '#333333']]);
        $this->first->update(['colors' => ['primary' => '#FF671F']]);
        $this->second->update(['colors' => null]);

        $user = User::factory()->create(['company_id' => $this->first->id]);
        $user->givePermissionTo('companies.view_all');

        $this->actingAs($user)
            ->get(route('home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('companies.0.colors', ['primary' => '#FF671F', 'accent' => '#222222', 'dark' => '#333333'])
                ->where('companies.1.colors', ['primary' => '#111111', 'accent' => '#222222', 'dark' => '#333333'])
            );
    }

    public function test_regular_user_only_receives_own_company(): void
    {
        $user = User::factory()->create(['company_id' => $this->third->id]);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('companies', 1)
                ->where('companies.0.id', $this->third->id)
                ->where('current_company_id', $this->third->id)
                ->where('auth.user.company_id', $this->third->id)
            );
    }

    public function test_regular_user_of_an_inactive_company_receives_none(): void
    {
        $this->third->update(['is_active' => false]);
        $user = User::factory()->create(['company_id' => $this->third->id]);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertInertia(fn (Assert $page) => $page->where('companies', []));
    }

    public function test_auth_user_avatar_url_comes_from_the_column(): void
    {
        $user = User::factory()->create([
            'company_id' => $this->first->id,
            'avatar_url' => 'https://example.test/avatar.png',
        ]);

        $this->actingAs($user)
            ->get(route('home'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.avatar_url', 'https://example.test/avatar.png')
                ->where('auth.user.company_id', $this->first->id)
            );
    }
}
