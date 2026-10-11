<?php

namespace Tests\Feature\Assistant;

use App\Models\KnowledgeItem;
use Spatie\Activitylog\Models\Activity;

/**
 * `assistant.knowledge.{store,update,destroy,review}`.
 */
class KnowledgeWriteTest extends AssistantTestCase
{
    public function test_store_creates_a_keyed_card_with_companies_links_and_activity(): void
    {
        $product = $this->product(['slug' => 'toma-pedidos', 'name' => 'Toma Pedidos SAP']);
        $related = $this->card(['title' => 'Pedidos a SAP']);

        $response = $this->actingAs($this->jhon)
            ->post(route('assistant.knowledge.store'), $this->payload([
                'company_ids' => [$this->segurex->id, $this->derivados->id],
                'related_card_keys' => [$related->key],
                'related_product_slugs' => [$product->slug],
            ]))
            ->assertSessionHasNoErrors();

        $card = KnowledgeItem::query()->latest('id')->firstOrFail();
        $response->assertRedirect(route('assistant.knowledge.show', $card))->assertSessionHas('success', "Ficha {$card->key} creada.");
        $this->assertMatchesRegularExpression('/^K-\d{3,}$/', $card->key);
        $this->assertNotSame($related->key, $card->key);
        $this->assertSame('report', $card->type);
        $this->assertSame('Gerencia y coordinadores de ventas', $card->fields['recipients']);
        $this->assertNull($card->fields['format']);
        $this->assertSame('2027-04-07', $card->review_at->toDateString());
        $this->assertSame('manual', $card->source);
        $this->assertTrue($card->is_current);
        $this->assertSame($this->jhon->id, $card->created_by_id);
        $this->assertEqualsCanonicalizing([$this->segurex->id, $this->derivados->id], $card->companies->pluck('id')->all());
        $this->assertSame([$related->key], $card->relatedItems->pluck('key')->all());
        $this->assertSame([$product->slug], $card->relatedProducts->pluck('slug')->all());
        $this->assertStringContainsString('Gerencia y coordinadores', (string) $card->search_text);

        $activity = Activity::query()->where('log_name', 'knowledge')->latest('id')->firstOrFail();
        $this->assertSame('creó la ficha', $activity->description);
        $this->assertSame('created', $activity->event);
        $this->assertTrue($activity->subject->is($card));
        $this->assertTrue($activity->causer->is($this->jhon));
    }

    public function test_store_keeps_only_the_fields_of_the_type_and_drops_empty_steps(): void
    {
        $this->actingAs($this->jhon)
            ->post(route('assistant.knowledge.store'), $this->payload([
                'type' => 'procedure',
                'title' => 'Conectarse a la VPN',
                'fields' => ['steps' => ['Abrir el cliente', 'Ir a [RUTA EN SAP]'], 'who_can' => '', 'recipients' => 'colado'],
            ]))
            ->assertSessionHasNoErrors();

        $card = KnowledgeItem::query()->latest('id')->firstOrFail();
        $this->assertSame(['steps' => ['Abrir el cliente', 'Ir a [RUTA EN SAP]'], 'who_can' => null], $card->fields);
    }

    public function test_store_validates_in_spanish_with_laravel_shaped_keys(): void
    {
        $this->actingAs($this->jhon)
            ->post(route('assistant.knowledge.store'), $this->payload([
                'title' => '',
                'fields' => ['shows' => ''],
                'company_ids' => [],
                'visibility' => 'todos',
                'review_at' => 'mañana',
            ]))
            ->assertSessionHasErrors([
                'title' => 'Escribe el título.',
                'fields.shows' => 'Completa «Qué muestra».',
                'company_ids' => 'Elige al menos una empresa.',
                'visibility' => 'Elige quién puede ver la ficha.',
                'review_at' => 'Escribe la fecha de revisión.',
            ]);

        $this->actingAs($this->jhon)
            ->post(route('assistant.knowledge.store'), $this->payload([
                'type' => 'procedure',
                'fields' => ['steps' => [], 'who_can' => null],
            ]))
            ->assertSessionHasErrors(['fields.steps' => 'Escribe al menos un paso.']);

        $this->actingAs($this->jhon)
            ->post(route('assistant.knowledge.store'), $this->payload(['area_id' => $this->derivadosVentas->id]))
            ->assertSessionHasErrors(['area_id' => 'El área debe ser de una de las empresas de la ficha.']);

        $this->actingAs($this->jhon)
            ->post(route('assistant.knowledge.store'), $this->payload(['related_card_keys' => ['K-999']]))
            ->assertSessionHasErrors(['related_card_keys']);

        $this->assertSame(0, KnowledgeItem::query()->count());
    }

    public function test_managers_without_view_all_only_write_cards_of_their_company(): void
    {
        $manager = $this->user($this->segurex, 'Usuario final', 'Gestor Segurex');
        $manager->givePermissionTo(['assistant.use', 'assistant.knowledge.manage']);

        $this->actingAs($manager)
            ->post(route('assistant.knowledge.store'), $this->payload(['company_ids' => [$this->derivados->id], 'area_id' => null]))
            ->assertSessionHasErrors(['company_ids' => 'Solo puedes crear fichas de tu empresa.']);
    }

    public function test_secrets_are_refused_in_any_text_of_the_card(): void
    {
        $this->actingAs($this->jhon)
            ->post(route('assistant.knowledge.store'), $this->payload([
                'type' => 'system',
                'fields' => ['what_is' => 'ERP', 'runs_on' => null, 'access' => 'usuario admin, password: Segurex2026!', 'version' => null],
            ]))
            ->assertSessionHasErrors(['fields.access' => 'Parece una contraseña o una llave. Las credenciales van en Accesos o en la bóveda, nunca en una ficha.']);

        $this->actingAs($this->jhon)
            ->post(route('assistant.knowledge.store'), $this->payload(['description' => 'token sk-abcdefghijklmnopqrstuvwxyz123456']))
            ->assertSessionHasErrors(['description']);

        $this->actingAs($this->jhon)
            ->post(route('assistant.knowledge.store'), $this->payload(['description' => 'Se entra con usuario de SAP; la contraseña la da TI.']))
            ->assertSessionHasNoErrors();
    }

    public function test_update_logs_what_changed_and_keeps_the_type(): void
    {
        $this->actingAs($this->jhon)->post(route('assistant.knowledge.store'), $this->payload());
        $card = KnowledgeItem::query()->latest('id')->firstOrFail();

        $this->actingAs($this->jhon)
            ->put(route('assistant.knowledge.update', $card), $this->payload([
                'fields' => [...$this->payload()['fields'], 'recipients' => 'Gerencia', 'schedule' => 'Lunes 7:00 a. m.'],
                'visibility' => 'it',
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('assistant.knowledge.show', $card));

        $card->refresh();
        $this->assertSame('Gerencia', $card->fields['recipients']);
        $this->assertSame('it', $card->visibility);
        $this->assertSame($this->jhon->id, $card->updated_by_id);
        $this->assertSame('cambió destinatarios, horario y visibilidad', Activity::query()->where('log_name', 'knowledge')->latest('id')->value('description'));

        $this->actingAs($this->jhon)
            ->put(route('assistant.knowledge.update', $card), $this->payload(['type' => 'system', 'visibility' => 'it', 'fields' => [...$this->payload()['fields'], 'recipients' => 'Gerencia', 'schedule' => 'Lunes 7:00 a. m.']]))
            ->assertSessionHasErrors(['type' => 'El tipo de una ficha no se puede cambiar.']);

        $before = Activity::query()->count();
        $this->actingAs($this->jhon)
            ->put(route('assistant.knowledge.update', $card), $this->payload(['visibility' => 'it', 'fields' => [...$this->payload()['fields'], 'recipients' => 'Gerencia', 'schedule' => 'Lunes 7:00 a. m.']]))
            ->assertSessionHasNoErrors();
        $this->assertSame($before, Activity::query()->count(), 'An update without changes logs nothing.');
    }

    public function test_review_moves_the_next_review_six_months_and_logs_it(): void
    {
        $card = $this->card(['review_at' => '2026-09-30']);

        $this->actingAs($this->jhon)
            ->post(route('assistant.knowledge.review', $card))
            ->assertRedirect(route('assistant.knowledge.show', $card));

        $card->refresh();
        $this->assertSame('2027-04-07', $card->review_at->toDateString());
        $this->assertSame($this->jhon->id, $card->last_reviewed_by_id);
        $this->assertNotNull($card->last_reviewed_at);
        $this->assertSame('la marcó como revisada', Activity::query()->where('log_name', 'knowledge')->latest('id')->value('description'));
    }

    public function test_destroy_soft_deletes_and_redirects_to_the_list(): void
    {
        $card = $this->card();

        $this->actingAs($this->jhon)
            ->delete(route('assistant.knowledge.destroy', $card))
            ->assertRedirect(route('assistant.knowledge.index'))
            ->assertSessionHas('success', "Eliminaste la ficha {$card->key}.");

        $this->assertSoftDeleted($card);
        $this->assertSame('eliminó la ficha', Activity::query()->where('log_name', 'knowledge')->latest('id')->value('description'));
        $this->actingAs($this->jhon)->get(route('assistant.knowledge.show', $card->key))->assertNotFound();
    }
}
