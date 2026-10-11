<?php

namespace Tests\Feature\Assistant\Telegram;

use App\Models\KnowledgeDraft;
use App\Models\KnowledgeItem;
use App\Telegram\Handlers\LearnCallbackHandler;
use App\Telegram\TelegramHtml;
use Spatie\Permission\PermissionRegistrar;

/**
 * `/aprender` on Telegram (telegram-format.md §6): draft summary with
 * Aprobar / Descartar / Editar en el Gestor TI, approve with title and
 * company, discard with a reason written in the next message.
 */
class TelegramLearnTest extends TelegramAssistantTestCase
{
    private string $text;

    protected function setUp(): void
    {
        parent::setUp();

        // The heuristic parser (no AI): deterministic drafts.
        config(['services.deepseek.api_key' => null]);
        $this->link($this->jhon);
        $this->text = "la VPN de {$this->derivados->name} usa FortiClient y la cuenta la crea Leonardo";
    }

    private function learn(): KnowledgeDraft
    {
        $this->send('/aprender '.$this->text);

        return KnowledgeDraft::query()->latest('id')->firstOrFail();
    }

    public function test_aprender_creates_a_telegram_draft_and_answers_with_the_summary_and_buttons(): void
    {
        $draft = $this->learn();

        $this->assertSame(['telegram', self::CHAT, 'pending'], [$draft->source, $draft->telegram_chat_id, $draft->status]);
        $this->assertSame($this->text, $draft->message);

        $sent = $this->lastSent();
        $this->assertSame($this->nextMessageId, $draft->telegram_message_id);
        $this->assertSame('HTML', $sent['parse_mode']);
        $lines = explode("\n", $sent['text']);
        $this->assertSame('<b>Borrador de ficha · pendiente de aprobar</b>', $lines[0]);
        $this->assertSame('<b>'.TelegramHtml::escape($draft->proposed['title']).'</b>', $lines[1]);
        $this->assertStringStartsWith('<i>', $lines[2]);
        $this->assertSame([$this->derivados->id], $draft->proposed['company_ids']);
        $this->assertStringContainsString(TelegramHtml::escape($this->derivados->short_name ?: $this->derivados->name), $lines[2]);
        $this->assertStringContainsString('Solo TI', $lines[2]);

        $buttons = $this->buttons($sent);
        $this->assertSame(['Aprobar', 'Descartar', 'Editar en el Gestor TI'], array_keys($buttons));
        $this->assertStringStartsWith('learn:ok:'.$draft->id.':', $buttons['Aprobar']['callback_data']);
        $this->assertStringStartsWith('learn:no:'.$draft->id.':', $buttons['Descartar']['callback_data']);
        $this->assertLessThanOrEqual(64, strlen($buttons['Aprobar']['callback_data']));
        $this->assertSame(route('assistant.drafts.index', ['borrador' => $draft->id, 'desde' => 'telegram']), $buttons['Editar en el Gestor TI']['url']);
        $this->assertNoProviderCall();
    }

    public function test_aprender_needs_knowledge_management_and_text(): void
    {
        $reader = $this->reader();
        $this->link($reader, '5151');

        $this->send('/aprender algo nuevo', '5151');
        $this->assertSame(LearnCallbackHandler::NOT_ALLOWED, $this->lastSent()['text']);

        $this->send('/aprender');
        $this->assertSame('Escribe qué quieres que aprenda después de /aprender.', $this->lastSent()['text']);

        $this->assertSame(0, KnowledgeDraft::query()->count());
    }

    public function test_approve_publishes_the_card_edits_the_draft_message_and_links_the_card(): void
    {
        $draft = $this->learn();

        $this->press($this->buttons($this->lastSent())['Aprobar']['callback_data'], $draft->telegram_message_id);

        $draft->refresh();
        $card = KnowledgeItem::query()->sole();
        $this->assertSame(['approved', $card->id, $this->jhon->id], [$draft->status, $draft->knowledge_item_id, $draft->decided_by_id]);
        $this->assertSame('learn', $card->source);

        $edit = $this->telegram('editMessageText')[0];
        $this->assertSame([self::CHAT, $draft->telegram_message_id, 'HTML'], [$edit['chat_id'], $edit['message_id'], $edit['parse_mode']]);
        $this->assertStringEndsWith("\nAprobado por ti · 9:40 a. m.", $edit['text']);
        $this->assertArrayNotHasKey('reply_markup', $edit);

        $this->assertSame(
            "Ficha {$card->key} creada: <a href=\"".route('assistant.knowledge.show', ['card' => $card->key]).'">'.TelegramHtml::escape($card->title).'</a>. Desde ya la uso para responder.',
            $this->lastSent()['text'],
        );

        // A second tap changes nothing.
        $this->press($this->buttons($this->telegram('sendMessage')[0])['Aprobar']['callback_data'], $draft->telegram_message_id);
        $this->assertSame('Este borrador ya se decidió.', $this->lastCallbackAnswer());
        $this->assertSame(1, KnowledgeItem::query()->count());
    }

    public function test_approve_says_what_is_missing(): void
    {
        $draft = $this->learn();
        $draft->forceFill(['proposed' => [...$draft->proposed, 'company_ids' => []]])->save();

        $this->press($this->buttons($this->lastSent())['Aprobar']['callback_data'], $draft->telegram_message_id);

        $this->assertSame(LearnCallbackHandler::NEEDS_COMPANY, $this->lastSent()['text']);
        $this->assertSame('pending', $draft->fresh()->status);

        $draft->forceFill(['proposed' => [...$draft->proposed, 'title' => ' ']])->save();
        $this->press($this->buttons($this->telegram('sendMessage')[0])['Aprobar']['callback_data'], $draft->telegram_message_id);
        $this->assertSame(LearnCallbackHandler::NEEDS_TITLE, $this->lastSent()['text']);
        $this->assertSame(0, KnowledgeItem::query()->count());
    }

    public function test_discard_asks_for_the_reason_and_stores_it_before_the_assistant_sees_it(): void
    {
        config(['services.deepseek.api_key' => 'test-deepseek-key']);
        $draft = $this->learn();

        $this->press($this->buttons($this->lastSent())['Descartar']['callback_data'], $draft->telegram_message_id);
        $this->assertStringContainsString('por qué', $this->lastSent()['text']);
        $this->assertSame('pending', $draft->fresh()->status);

        $this->send('Ya está en K-031');

        $draft->refresh();
        $this->assertSame(['discarded', 'Ya está en K-031', $this->jhon->id], [$draft->status, $draft->discard_reason, $draft->decided_by_id]);
        $this->assertStringEndsWith("\nDescartado por ti · 9:40 a. m.", $this->telegram('editMessageText')[0]['text']);
        $this->assertSame('Listo, descarté el borrador.', $this->lastSent()['text']);
        $this->assertNoProviderCall();
    }

    public function test_cancel_keeps_the_draft_pending(): void
    {
        $draft = $this->learn();

        $this->press($this->buttons($this->lastSent())['Descartar']['callback_data'], $draft->telegram_message_id);
        $this->send('/cancelar');

        $this->assertSame('Listo, no se descartó. El borrador sigue pendiente.', $this->lastSent()['text']);
        $this->assertSame('pending', $draft->fresh()->status);
    }

    public function test_tampered_foreign_or_unauthorized_taps_change_nothing(): void
    {
        $draft = $this->learn();
        $data = $this->buttons($this->lastSent())['Aprobar']['callback_data'];

        $this->press(substr($data, 0, -3).'xyz', $draft->telegram_message_id);
        $this->assertSame('Esta acción ya no está disponible.', $this->lastCallbackAnswer());

        $this->press('learn:ok:'.$draft->id, $draft->telegram_message_id);
        $this->assertSame('Esta acción ya no está disponible.', $this->lastCallbackAnswer());

        // Valid data, but pressed from another linked chat.
        $this->link($this->superAdmin, '8181');
        $this->press($data, $draft->telegram_message_id, '8181');
        $this->assertSame('Esta acción ya no está disponible.', $this->lastCallbackAnswer());

        // The permission is checked again at tap time.
        $this->jhon->syncRoles([]);
        $this->jhon->syncPermissions(['assistant.use']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->press($data, $draft->telegram_message_id);
        $this->assertSame(LearnCallbackHandler::NOT_ALLOWED, $this->lastCallbackAnswer());

        $this->assertSame('pending', $draft->fresh()->status);
        $this->assertSame(0, KnowledgeItem::query()->count());
    }
}
