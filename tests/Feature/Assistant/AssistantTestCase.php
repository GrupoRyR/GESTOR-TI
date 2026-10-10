<?php

namespace Tests\Feature\Assistant;

use App\Models\Area;
use App\Models\Company;
use App\Models\KnowledgeItem;
use App\Models\User;
use Tests\Feature\Ai\Knowledge\KnowledgeTestCase;

/**
 * Asistente TI (types/pages/assistant-*.ts). Reuses the Gestor IA fixture:
 * three companies, Jhon (Líder IA: `assistant.use` + `assistant.knowledge.manage`,
 * global) and Leonardo (Súper Admin). `reader()` is a person with
 * `assistant.use` only, as the read-only mocks.
 */
abstract class AssistantTestCase extends KnowledgeTestCase
{
    /** Bogotá "today" of every test (review dates are compared with it). */
    protected const TODAY = '2026-10-07';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 10, 7)->setTime(9, 40));
    }

    /**
     * Usuario final with `assistant.use` only (no management, no credentials).
     */
    protected function reader(?Company $company = null, string $name = 'Lectora del asistente'): User
    {
        $user = $this->user($company ?? $this->segurex, 'Usuario final', $name, ($company ?? $this->segurex)->is($this->segurex) ? $this->ventas : null);
        $user->givePermissionTo('assistant.use');

        return $user;
    }

    /**
     * A card in the given companies (Segurex by default).
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<Company>|null  $companies
     */
    protected function card(array $attributes = [], ?array $companies = null, ?Area $area = null): KnowledgeItem
    {
        $card = KnowledgeItem::factory()->create(array_replace([
            'area_id' => ($area ?? $this->ventas)->id,
            'owner_id' => $this->paola->id,
        ], $attributes));
        $card->companies()->sync(array_map(fn (Company $company) => $company->id, $companies ?? [$this->segurex]));

        return $card->fresh();
    }

    /**
     * A valid `KnowledgeCardPayload` for a report of Segurex · Ventas.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function payload(array $overrides = []): array
    {
        return array_replace([
            'type' => 'report',
            'title' => 'Informe de ventas diario',
            'description' => 'Lo envía Toma Pedidos SAP a las 6:00 a. m.',
            'fields' => [
                'shows' => 'Los pedidos del día anterior, tomados de SAP B1',
                'source_system' => 'Toma Pedidos SAP',
                'recipients' => 'Gerencia y coordinadores de ventas',
                'schedule' => 'Todos los días a las 6:00 a. m.',
                'format' => null,
            ],
            'company_ids' => [$this->segurex->id],
            'area_id' => $this->ventas->id,
            'owner_id' => $this->paola->id,
            'visibility' => 'company',
            'review_at' => '2027-04-07',
            'related_card_keys' => [],
            'related_product_slugs' => [],
            'unanswered_id' => null,
            'draft_id' => null,
        ], $overrides);
    }
}
