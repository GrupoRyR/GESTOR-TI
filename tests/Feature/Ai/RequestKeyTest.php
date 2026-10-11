<?php

namespace Tests\Feature\Ai;

use App\Domain\Ai\RequestKeys;
use App\Models\AiRequest;

class RequestKeyTest extends AiTestCase
{
    public function test_keys_are_sequential_and_shared_by_every_company(): void
    {
        $first = $this->request();
        $second = $this->request(['company_id' => $this->derivados->id, 'area_id' => $this->derivadosVentas->id]);
        $third = $this->request(['company_id' => $this->third->id, 'area_id' => null]);

        $numbers = array_map(fn (AiRequest $request) => (int) substr($request->key, 4), [$first, $second, $third]);

        $this->assertSame('GIA-', substr($first->key, 0, 4));
        $this->assertSame([$numbers[0], $numbers[0] + 1, $numbers[0] + 2], $numbers);
    }

    public function test_a_deleted_request_never_frees_its_key(): void
    {
        $first = $this->request();
        $first->delete();

        $second = $this->request();

        $this->assertNotSame($first->key, $second->key);
        $this->assertSame(2, AiRequest::withTrashed()->distinct()->count('key'));
    }

    public function test_the_sequence_can_start_after_an_imported_number(): void
    {
        app(RequestKeys::class)->ensureAtLeast(147);

        $this->assertSame('GIA-148', $this->request()->key);
    }

    public function test_an_explicit_key_is_kept(): void
    {
        $this->assertSame('GIA-900', $this->request(['key' => 'GIA-900'])->key);
    }

    public function test_requests_are_bound_by_key(): void
    {
        $this->assertSame('key', (new AiRequest)->getRouteKeyName());
    }
}
