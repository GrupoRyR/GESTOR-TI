<?php

namespace Tests\Feature\Admin;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CompaniesAdminTest extends TestCase
{
    use RefreshDatabase;

    private const SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10" fill="#FF671F"/></svg>';

    /** 1×1 transparent PNG. */
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['companies.manage', 'companies.manage_tenant'] as $permission) {
            Permission::create(['name' => $permission]);
        }

        Storage::fake('public');

        $this->company = Company::factory()->create([
            'name' => 'Segurex Latam',
            'short_name' => 'Segurex',
            'slug' => 'segurex',
            'tenant_id' => '97cd024c-cd7a-419b-b385-f47b5f5816c8',
            'domains' => ['segurex.com'],
            'sender_email' => 'segurex.info@segurex.com',
            'colors' => ['primary' => '#FF671F', 'accent' => '#666666', 'dark' => '#333333'],
        ]);
    }

    private function manager(bool $tenant = false): User
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);
        $user->givePermissionTo($tenant ? ['companies.manage', 'companies.manage_tenant'] : ['companies.manage']);

        return $user;
    }

    /**
     * Body of `admin.companies.update` / `.store` as the pages send it.
     *
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Segurex Latam',
            'short_name' => 'Segurex',
            'slug' => 'segurex',
            'domains' => ['segurex.com'],
            'colors' => ['primary' => '#FF671F', 'accent' => '#666666', 'dark' => '#333333'],
            'sender_email' => 'segurex.info@segurex.com',
            'active' => '1',
        ], $overrides);
    }

    /**
     * POST + `_method=PUT`, multipart (what CompanyEditPanel sends).
     *
     * @param  array<string, mixed>  $data
     */
    private function sendUpdate(User $user, array $data): TestResponse
    {
        return $this->actingAs($user)->post(
            route('admin.companies.update', $this->company),
            ['_method' => 'PUT', ...$data],
            ['Content-Type' => 'multipart/form-data'],
        );
    }

    private function svg(string $name = 'logo.svg', string $content = self::SVG): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function png(string $name = 'logo.png'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode(self::PNG_BASE64));
    }

    // ---- Index / edit ---------------------------------------------------

    public function test_index_requires_permission(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.companies.index'))
            ->assertForbidden();
    }

    public function test_index_returns_exact_props(): void
    {
        Company::factory()->inactive()->create(['name' => 'Zeta', 'tenant_id' => null, 'sender_email' => null]);
        Employee::factory()->for($this->company)->count(2)->create();
        Employee::factory()->for($this->company)->inactive()->create();

        $this->actingAs($this->manager())
            ->get(route('admin.companies.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Companies/Index', false)
                ->has('admin_companies', 2)
                ->where('admin_companies.0', [
                    'id' => $this->company->id,
                    'slug' => 'segurex',
                    'name' => 'Segurex Latam',
                    'short_name' => 'Segurex',
                    'logo_url' => null,
                    'logo_dark_url' => null,
                    'colors' => ['primary' => '#FF671F', 'accent' => '#666666', 'dark' => '#333333'],
                    'tenant_id' => '97cd024c-cd7a-419b-b385-f47b5f5816c8',
                    'domains' => ['segurex.com'],
                    'sender_email' => 'segurex.info@segurex.com',
                    'active' => true,
                    'employees_count' => 2,
                ])
                ->where('admin_companies.1.name', 'Zeta')
                ->where('admin_companies.1.active', false)
                ->where('admin_companies.1.tenant_id', '')
                ->where('admin_companies.1.sender_email', '')
                ->where('selected', null)
                ->where('can_change_tenant', false)
                // The shared `companies` (selector) is not overwritten.
                ->where('companies', fn ($companies) => collect($companies)->pluck('id')->all() === [$this->company->id])
            );
    }

    public function test_edit_renders_the_index_with_the_selected_company(): void
    {
        $this->actingAs($this->manager(tenant: true))
            ->get(route('admin.companies.edit', $this->company))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Companies/Index', false)
                ->has('admin_companies', 1)
                ->where('selected.id', $this->company->id)
                ->where('selected.employees_count', 0)
                ->where('can_change_tenant', true)
            );
    }

    // ---- Update -----------------------------------------------------------

    public function test_update_maps_active_and_keeps_other_fields(): void
    {
        $this->sendUpdate($this->manager(), $this->payload([
            'name' => 'Segurex Latam S.A.S.',
            'domains' => ['segurex.com', 'segurex.co'],
            'active' => '0',
        ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.companies.edit', $this->company))
            ->assertSessionHas('success', 'Empresa actualizada.');

        $company = $this->company->fresh();
        $this->assertSame('Segurex Latam S.A.S.', $company->name);
        $this->assertSame(['segurex.com', 'segurex.co'], $company->domains);
        $this->assertFalse($company->is_active);
        $this->assertSame('97cd024c-cd7a-419b-b385-f47b5f5816c8', $company->tenant_id);
    }

    public function test_update_uploads_logos_and_deletes_the_previous_file(): void
    {
        Storage::disk('public')->put('companies/'.$this->company->id.'/old.svg', self::SVG);
        $this->company->update(['logo_path' => 'companies/'.$this->company->id.'/old.svg']);

        $this->sendUpdate($this->manager(), $this->payload([
            'logo' => $this->svg(),
            'logo_dark' => $this->png('dark.png'),
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $company = $this->company->fresh();
        $this->assertStringStartsWith('companies/'.$company->id.'/', $company->logo_path);
        $this->assertStringEndsWith('.svg', $company->logo_path);
        $this->assertStringEndsWith('.png', $company->logo_dark_path);
        Storage::disk('public')->assertExists([$company->logo_path, $company->logo_dark_path]);
        Storage::disk('public')->assertMissing('companies/'.$company->id.'/old.svg');
        $this->assertSame(Storage::disk('public')->url($company->logo_path), $company->logo_url);
    }

    public function test_update_without_logo_keeps_the_current_one(): void
    {
        $this->company->update(['logo_path' => 'companies/'.$this->company->id.'/kept.svg']);

        $this->sendUpdate($this->manager(), $this->payload())->assertSessionHasNoErrors();

        $this->assertSame('companies/'.$this->company->id.'/kept.svg', $this->company->fresh()->logo_path);
    }

    public function test_update_rejects_unsafe_or_wrong_logo_files(): void
    {
        $manager = $this->manager();

        $this->sendUpdate($manager, $this->payload(['logo' => $this->svg('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')]))
            ->assertSessionHasErrors('logo');
        $this->sendUpdate($manager, $this->payload(['logo' => $this->svg('x.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>')]))
            ->assertSessionHasErrors('logo');
        $this->sendUpdate($manager, $this->payload(['logo' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf')]))
            ->assertSessionHasErrors('logo');
        $this->sendUpdate($manager, $this->payload(['logo_dark' => UploadedFile::fake()->createWithContent('big.svg', '<svg xmlns="http://www.w3.org/2000/svg">'.str_repeat('<g/>', 140000).'</svg>')]))
            ->assertSessionHasErrors('logo_dark');
        // A text file renamed to .png is not a PNG.
        $this->sendUpdate($manager, $this->payload(['logo' => UploadedFile::fake()->createWithContent('fake.png', 'hola')]))
            ->assertSessionHasErrors('logo');

        $this->assertNull($this->company->fresh()->logo_path);
    }

    public function test_update_validates_fields(): void
    {
        $this->sendUpdate($this->manager(), $this->payload([
            'slug' => 'No Valido',
            'domains' => ['no es dominio'],
            'colors' => ['primary' => 'orange', 'accent' => '#666666', 'dark' => '#333333'],
            'sender_email' => 'nope',
        ]))->assertSessionHasErrors(['slug', 'domains.0', 'colors.primary', 'sender_email']);
    }

    public function test_sender_email_must_belong_to_a_company_domain(): void
    {
        $message = 'El correo emisor debe ser de uno de los dominios de la empresa.';

        $this->sendUpdate($this->manager(), $this->payload([
            'domains' => ['segurex.com', 'segurex.co'],
            'sender_email' => 'info@gmail.com',
        ]))->assertSessionHasErrors(['sender_email' => $message]);

        $this->sendUpdate($this->manager(), $this->payload([
            'domains' => ['segurex.com', 'segurex.co'],
            'sender_email' => 'Info@Segurex.CO',
        ]))->assertSessionHasNoErrors();

        $this->assertSame('info@segurex.co', $this->company->fresh()->sender_email);

        $this->actingAs($this->manager(tenant: true))
            ->post(route('admin.companies.store'), $this->payload([
                'slug' => 'nueva',
                'tenant_id' => '22222222-2222-2222-2222-222222222222',
                'domains' => ['nueva.com'],
                'sender_email' => 'info@segurex.com',
                'logo' => $this->svg(),
            ]))
            ->assertSessionHasErrors(['sender_email' => $message]);
    }

    public function test_update_without_tenant_permission_cannot_change_tenant_id(): void
    {
        $this->sendUpdate($this->manager(), $this->payload([
            'tenant_id' => '11111111-1111-1111-1111-111111111111',
        ]))->assertForbidden();

        $this->assertSame('97cd024c-cd7a-419b-b385-f47b5f5816c8', $this->company->fresh()->tenant_id);
    }

    public function test_update_sending_the_same_tenant_id_is_allowed_without_tenant_permission(): void
    {
        $this->sendUpdate($this->manager(), $this->payload([
            'tenant_id' => '97cd024c-cd7a-419b-b385-f47b5f5816c8',
        ]))->assertSessionHasNoErrors()->assertRedirect();
    }

    public function test_tenant_manager_can_change_tenant_id_and_it_is_audited(): void
    {
        $manager = $this->manager(tenant: true);

        $this->sendUpdate($manager, $this->payload([
            'tenant_id' => '11111111-1111-1111-1111-111111111111',
        ]))->assertSessionHasNoErrors()->assertRedirect();

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

    // ---- Create / store ---------------------------------------------------

    public function test_create_requires_tenant_permission(): void
    {
        $this->actingAs($this->manager())
            ->get(route('admin.companies.create'))
            ->assertForbidden();
    }

    public function test_create_renders_the_wizard_props(): void
    {
        config([
            'services.microsoft.client_id' => 'abc-123',
            'gestor.microsoft.admin_consent_url_template' => 'https://login.microsoftonline.com/{tenant}/adminconsent?client_id={client_id}',
        ]);

        $this->actingAs($this->manager(tenant: true))
            ->get(route('admin.companies.create'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Companies/Create', false)
                ->where('admin_consent_url_template', 'https://login.microsoftonline.com/{tenant}/adminconsent?client_id=abc-123')
                ->missing('initial_step')
                ->missing('draft')
            );
    }

    public function test_store_requires_tenant_permission(): void
    {
        $this->actingAs($this->manager())
            ->post(route('admin.companies.store'), $this->payload([
                'slug' => 'nueva',
                'tenant_id' => '22222222-2222-2222-2222-222222222222',
                'logo' => $this->svg(),
            ]))
            ->assertForbidden();

        $this->assertSame(1, Company::count());
    }

    public function test_tenant_manager_stores_a_company_and_goes_to_its_edit_page(): void
    {
        $this->actingAs($this->manager(tenant: true))
            ->post(route('admin.companies.store'), $this->payload([
                'name' => 'Nueva S.A.S.',
                'short_name' => 'Nueva',
                'slug' => 'nueva',
                'tenant_id' => '22222222-2222-2222-2222-222222222222',
                'domains' => ['nueva.com'],
                'sender_email' => 'info@nueva.com',
                'logo' => $this->svg(),
                'active' => '1',
            ]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Empresa creada.')
            ->assertRedirect(route('admin.companies.edit', Company::where('slug', 'nueva')->firstOrFail()));

        $company = Company::where('slug', 'nueva')->firstOrFail();
        $this->assertSame(['nueva.com'], $company->domains);
        $this->assertTrue($company->is_active);
        $this->assertSame('Nueva S.A.S.', $company->sender_name);
        $this->assertStringStartsWith('companies/'.$company->id.'/', $company->logo_path);
        Storage::disk('public')->assertExists($company->logo_path);
        $this->assertTrue(Activity::where('subject_id', $company->id)->where('subject_type', $company->getMorphClass())->where('event', 'created')->exists());
    }

    public function test_store_validation_error_reopens_the_first_failing_step(): void
    {
        $payload = $this->payload([
            'slug' => 'nueva',
            'tenant_id' => 'not-a-guid',
            'logo' => $this->svg(),
            'sender_email' => 'nope',
        ]);

        $this->actingAs($this->manager(tenant: true))
            ->from(route('admin.companies.create'))
            ->post(route('admin.companies.store'), $payload)
            ->assertRedirect(route('admin.companies.create'))
            ->assertSessionHasErrors(['tenant_id', 'sender_email']);

        $this->from(route('admin.companies.create'))
            ->followingRedirects()
            ->post(route('admin.companies.store'), $payload)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Companies/Create', false)
                ->where('initial_step', 2)
                ->has('errors.tenant_id')
                ->has('errors.sender_email')
            );

        $this->assertSame(1, Company::count());
    }

    public function test_store_requires_tenant_and_logo(): void
    {
        $this->actingAs($this->manager(tenant: true))
            ->post(route('admin.companies.store'), $this->payload(['slug' => 'nueva', 'domains' => ['nueva.com']]))
            ->assertSessionHasErrors(['tenant_id', 'logo']);
    }
}
