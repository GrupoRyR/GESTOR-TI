<?php

namespace Tests\Feature\Admin;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CompaniesAdminTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['companies.manage', 'companies.manage_tenant'] as $permission) {
            Permission::create(['name' => $permission]);
        }

        $this->company = Company::factory()->create([
            'slug' => 'segurex',
            'tenant_id' => '97cd024c-cd7a-419b-b385-f47b5f5816c8',
        ]);
    }

    private function manager(bool $tenant = false): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $user->givePermissionTo($tenant ? ['companies.manage', 'companies.manage_tenant'] : ['companies.manage']);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Segurex Latam',
            'short_name' => 'Segurex',
            'slug' => 'segurex',
            'domains' => ['segurex.com'],
            'logo_path' => null,
            'logo_dark_path' => null,
            'colors' => ['primary' => '#FF671F', 'accent' => '#666666', 'dark' => '#333333'],
            'sender_email' => 'segurex.info@segurex.com',
            'sender_name' => 'Segurex',
            'is_active' => true,
        ], $overrides);
    }

    public function test_index_requires_permission(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.companies.index'))
            ->assertForbidden();
    }

    public function test_index_returns_full_company_fields(): void
    {
        Company::factory()->inactive()->create();

        $this->actingAs($this->manager())
            ->get(route('admin.companies.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Companies/Index', false)
                ->has('companies', 2)
                ->has('companies.0', fn (Assert $company) => $company
                    ->hasAll([
                        'id', 'name', 'short_name', 'slug', 'tenant_id', 'domains',
                        'logo_path', 'logo_dark_path', 'logo_url', 'logo_dark_url',
                        'colors', 'sender_email', 'sender_name', 'is_active',
                    ])
                )
            );
    }

    public function test_update_without_tenant_permission_keeps_working_for_other_fields(): void
    {
        $this->actingAs($this->manager())
            ->put(route('admin.companies.update', $this->company), $this->payload(['sender_name' => 'Segurex Info']))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('Segurex Info', $this->company->fresh()->sender_name);
    }

    public function test_update_without_tenant_permission_cannot_change_tenant_id(): void
    {
        $this->actingAs($this->manager())
            ->put(route('admin.companies.update', $this->company), $this->payload([
                'tenant_id' => '11111111-1111-1111-1111-111111111111',
            ]))
            ->assertForbidden();

        $this->assertSame('97cd024c-cd7a-419b-b385-f47b5f5816c8', $this->company->fresh()->tenant_id);
    }

    public function test_update_sending_the_same_tenant_id_is_allowed_without_tenant_permission(): void
    {
        $this->actingAs($this->manager())
            ->put(route('admin.companies.update', $this->company), $this->payload([
                'tenant_id' => '97cd024c-cd7a-419b-b385-f47b5f5816c8',
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();
    }

    public function test_tenant_manager_can_change_tenant_id_and_it_is_audited(): void
    {
        $manager = $this->manager(tenant: true);

        $this->actingAs($manager)
            ->put(route('admin.companies.update', $this->company), $this->payload([
                'tenant_id' => '11111111-1111-1111-1111-111111111111',
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('11111111-1111-1111-1111-111111111111', $this->company->fresh()->tenant_id);

        $activity = Activity::query()
            ->where('subject_type', $this->company->getMorphClass())
            ->where('subject_id', $this->company->id)
            ->where('event', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($manager->id, $activity->causer_id);
        $this->assertSame('11111111-1111-1111-1111-111111111111', $activity->properties['attributes']['tenant_id']);
        $this->assertSame('97cd024c-cd7a-419b-b385-f47b5f5816c8', $activity->properties['old']['tenant_id']);
    }

    public function test_store_requires_tenant_permission(): void
    {
        $this->actingAs($this->manager())
            ->post(route('admin.companies.store'), $this->payload(['slug' => 'nueva', 'name' => 'Nueva S.A.S.']))
            ->assertForbidden();

        $this->assertSame(1, Company::count());
    }

    public function test_tenant_manager_can_store_a_company(): void
    {
        $this->actingAs($this->manager(tenant: true))
            ->post(route('admin.companies.store'), $this->payload([
                'name' => 'Nueva S.A.S.',
                'short_name' => 'Nueva',
                'slug' => 'nueva',
                'tenant_id' => '22222222-2222-2222-2222-222222222222',
                'domains' => ['nueva.com'],
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $company = Company::where('slug', 'nueva')->firstOrFail();
        $this->assertSame(['nueva.com'], $company->domains);
        $this->assertTrue(Activity::where('subject_id', $company->id)->where('event', 'created')->exists());
    }

    public function test_store_validates_fields(): void
    {
        $this->actingAs($this->manager(tenant: true))
            ->post(route('admin.companies.store'), $this->payload([
                'slug' => 'segurex',
                'tenant_id' => 'not-a-guid',
                'domains' => ['no es dominio'],
                'colors' => ['primary' => 'orange', 'accent' => '#666666', 'dark' => '#333333'],
                'sender_email' => 'nope',
            ]))
            ->assertSessionHasErrors(['slug', 'tenant_id', 'domains.0', 'colors.primary', 'sender_email']);
    }
}
