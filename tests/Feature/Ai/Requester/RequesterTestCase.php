<?php

namespace Tests\Feature\Ai\Requester;

use App\Enums\Ai\RequestStatus;
use App\Models\AiProduct;
use App\Models\AiRequest;
use App\Models\AiRequestStatusChange;
use App\Models\Company;
use Tests\Feature\Ai\AiTestCase;

/**
 * Requester pages (types/pages/ai-requester.ts). Page files live on the
 * design branch, so components are asserted without checking the file.
 */
abstract class RequesterTestCase extends AiTestCase
{
    /** Shared props every page carries (HandleInertiaRequests). */
    protected const SHARED = ['auth', 'companies', 'current_company_id', 'flash', 'notifications_unread', 'modules', 'errors'];

    /**
     * @param  list<Company>  $companies
     */
    protected function product(string $slug = 'toma-pedidos', string $name = 'Toma Pedidos SAP', array $companies = [], array $attributes = []): AiProduct
    {
        $product = AiProduct::factory()->create(['slug' => $slug, 'name' => $name, 'current_version' => 'v1.8.2', ...$attributes]);

        foreach ($companies === [] ? [$this->segurex] : $companies as $i => $company) {
            $product->companies()->attach($company->id, ['is_primary' => $i === 0]);
        }

        return $product;
    }

    /**
     * A request that reached production (with its history row).
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function inProduction(array $attributes = []): AiRequest
    {
        $request = $this->request(['status' => RequestStatus::InProduction, 'first_responded_at' => now(), ...$attributes]);

        AiRequestStatusChange::factory()->create([
            'ai_request_id' => $request->id,
            'from_status' => RequestStatus::InTesting,
            'to_status' => RequestStatus::InProduction,
            'user_id' => $this->jhon->id,
        ]);

        return $request;
    }

    /**
     * Prop keys of a page response, shared props included.
     *
     * @return list<string>
     */
    protected function propKeys(string $url): array
    {
        return array_keys($this->get($url)->viewData('page')['props']);
    }
}
