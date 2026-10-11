<?php

namespace Tests\Feature\Ai;

use App\Models\AiProduct;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class AiProductPolicyTest extends AiTestCase
{
    private AiProduct $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = AiProduct::factory()->create(['slug' => 'visualizador-cartera']);
        $this->product->companies()->attach($this->segurex->id, ['is_primary' => true]);
    }

    private function allows(User $user, string $ability, mixed $arguments = null): bool
    {
        return Gate::forUser($user)->allows($ability, $arguments ?? $this->product);
    }

    public function test_view_any_needs_the_ai_module(): void
    {
        $this->assertTrue($this->allows($this->paola, 'viewAny', AiProduct::class));
        $this->assertTrue($this->allows($this->jhon, 'viewAny', AiProduct::class));

        $outsider = User::factory()->create();
        $this->assertFalse($this->allows($outsider, 'viewAny', AiProduct::class));
    }

    public function test_view_for_manage_owner_and_users_of_a_product_company(): void
    {
        $this->assertTrue($this->allows($this->jhon, 'view'));
        $this->assertTrue($this->allows($this->paola, 'view'), 'Segurex user');
        $this->assertFalse($this->allows($this->derivadosUser, 'view'), 'Derivados user, Segurex-only product');

        $this->product->companies()->attach($this->derivados->id, ['is_primary' => false]);
        $this->assertTrue($this->allows($this->derivadosUser, 'view'), 'product shared with Derivados');

        $owner = $this->user($this->third, 'Usuario final', 'Dueño externo');
        $this->assertFalse($this->allows($owner, 'view'));
        $this->product->forceFill(['owner_user_id' => $owner->id])->save();
        $this->assertTrue($this->allows($owner, 'view'), 'product owner');
    }

    public function test_users_without_the_ai_module_cannot_view_their_company_products(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['company_id' => $this->segurex->id])->save();

        $this->assertFalse($this->allows($user, 'view'));
    }

    public function test_only_ai_manage_manages_products(): void
    {
        foreach (['create', 'update', 'delete', 'manageVersions'] as $ability) {
            $arguments = $ability === 'create' ? AiProduct::class : $this->product;

            $this->assertTrue($this->allows($this->jhon, $ability, $arguments), $ability);
            $this->assertFalse($this->allows($this->paola, $ability, $arguments), $ability);
            $this->assertFalse($this->allows($this->edward, $ability, $arguments), $ability);
        }
    }

    public function test_view_costs_needs_ai_costs_view(): void
    {
        $this->assertTrue($this->allows($this->edward, 'viewCosts'));
        $this->assertTrue($this->allows($this->jhon, 'viewCosts'));
        $this->assertFalse($this->allows($this->paola, 'viewCosts'));
    }
}
