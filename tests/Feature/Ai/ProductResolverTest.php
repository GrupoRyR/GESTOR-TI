<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\CreateRequest;
use App\Domain\Ai\ProductResolver;
use App\Enums\Ai\RequestType;
use App\Models\AiProduct;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ProductResolverTest extends AiTestCase
{
    private AiProduct $tomaPedidos;

    private AiProduct $portafolio;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();

        $this->tomaPedidos = AiProduct::factory()->create(['slug' => 'toma-pedidos', 'current_version' => 'v2.4.0']);
        $this->tomaPedidos->companies()->attach($this->segurex->id, ['is_primary' => true]);
        $this->portafolio = AiProduct::factory()->create(['slug' => 'portafolio', 'current_version' => 'v1.8.2']);
        $this->portafolio->companies()->attach($this->derivados->id, ['is_primary' => true]);
    }

    public function test_from_slug_finds_active_products_case_and_space_insensitive(): void
    {
        $resolver = app(ProductResolver::class);

        $this->assertTrue($resolver->fromSlug('toma-pedidos')->is($this->tomaPedidos));
        $this->assertTrue($resolver->fromSlug('  Toma-Pedidos ')->is($this->tomaPedidos));
        $this->assertNull($resolver->fromSlug('no-existe'));
        $this->assertNull($resolver->fromSlug(''));
        $this->assertNull($resolver->fromSlug(null));

        $this->portafolio->delete();
        $this->assertNull($resolver->fromSlug('portafolio'), 'soft-deleted products are not offered');
    }

    public function test_for_user_only_resolves_products_of_the_user_company(): void
    {
        $resolver = app(ProductResolver::class);

        $this->assertTrue($resolver->forUser('toma-pedidos', $this->paola)->is($this->tomaPedidos));
        $this->assertNull($resolver->forUser('portafolio', $this->paola));
        $this->assertTrue($resolver->forUser('portafolio', $this->derivadosUser)->is($this->portafolio));
    }

    public function test_version_defaults_to_the_current_version(): void
    {
        $resolver = app(ProductResolver::class);

        $this->assertSame('v2.4.0', $resolver->version($this->tomaPedidos, null));
        $this->assertSame('v2.4.0', $resolver->version($this->tomaPedidos, '  '));
        $this->assertSame('v2.3.9', $resolver->version($this->tomaPedidos, ' v2.3.9 '));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function data(array $overrides = []): array
    {
        return array_replace([
            'type' => RequestType::Improvement->value,
            'problem' => 'El pedido bloqueado por cartera no avisa al asesor.',
            'expected_result' => 'Un aviso al asesor.',
        ], $overrides);
    }

    public function test_create_request_accepts_the_product_by_slug_and_version(): void
    {
        $request = app(CreateRequest::class)->handle($this->paola, $this->data(['product' => 'toma-pedidos', 'product_version' => 'v2.3.0']));

        $this->assertSame($this->tomaPedidos->id, $request->product_id);
        $this->assertSame('v2.3.0', $request->product_version);
    }

    public function test_create_request_uses_the_current_version_when_none_is_given(): void
    {
        $request = app(CreateRequest::class)->handle($this->paola, $this->data(['product' => 'toma-pedidos']));

        $this->assertSame('v2.4.0', $request->product_version);
    }

    public function test_create_request_rejects_unknown_or_foreign_products(): void
    {
        foreach ([['product' => 'no-existe'], ['product' => 'portafolio'], ['product_id' => $this->portafolio->id], ['product_id' => 999]] as $data) {
            try {
                app(CreateRequest::class)->handle($this->paola, $this->data($data));
                $this->fail('expected a validation error for '.json_encode($data));
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('product', $exception->errors());
            }
        }
    }

    public function test_create_request_without_product_keeps_working(): void
    {
        $request = app(CreateRequest::class)->handle($this->paola, $this->data(['type' => RequestType::New->value]));

        $this->assertNull($request->product_id);
        $this->assertNull($request->product_version);
    }
}
