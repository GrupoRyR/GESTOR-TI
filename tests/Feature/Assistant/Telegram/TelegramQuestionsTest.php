<?php

namespace Tests\Feature\Assistant\Telegram;

use App\Domain\Assistant\AssistantEngine;
use App\Models\AiInteraction;
use App\Models\AssistantConversation;
use App\Models\UnansweredQuestion;
use App\Telegram\Assistant\TelegramAssistant;
use App\Telegram\Assistant\TypingIndicator;
use App\Telegram\UpdateHandler;

/**
 * Free-text questions to the bot (telegram-format.md §1–§2): typing,
 * after-response processing, HTML answers with sources, cuts, refusals,
 * off states and the per-chat rate limit.
 */
class TelegramQuestionsTest extends TelegramAssistantTestCase
{
    private function salesCard(string $title = 'Informe de ventas diario'): string
    {
        return $this->card(['title' => $title, 'visibility' => 'company'])->key;
    }

    public function test_a_question_through_the_webhook_sends_typing_and_then_the_html_answer_with_sources(): void
    {
        $key = $this->salesCard();
        $this->link($this->jhon);
        $this->provider(
            $this->tool('buscar_conocimiento', ['texto' => 'informe ventas diario']),
            $this->text("Sale a las **6:00 a. m.** desde Toma Pedidos SAP y llega por correo a:\n- Gerencia\n- Coordinadores de ventas de Segurex\nTrae los pedidos del día anterior desde SAP B1 [{$key}]."),
        );

        $this->postJson('/api/telegram/webhook/hook-secret', [
            'update_id' => 4001,
            'message' => ['message_id' => 3, 'chat' => ['id' => 4242, 'type' => 'private'], 'from' => ['id' => 4242], 'text' => '¿Quién recibe el informe de ventas diario y a qué hora sale?'],
        ])->assertOk()->assertExactJson(['ok' => true]);

        $this->assertSame(['sendChatAction', 'sendMessage'], $this->telegramMethods());
        $this->assertSame('typing', $this->telegram('sendChatAction')[0]['action']);

        $url = route('assistant.knowledge.show', ['card' => $key]);
        $this->assertSame([
            'chat_id' => self::CHAT,
            'text' => "Sale a las <b>6:00 a. m.</b> desde Toma Pedidos SAP y llega por correo a:\n• Gerencia\n• Coordinadores de ventas de Segurex\nTrae los pedidos del día anterior desde SAP B1.\n\n<b>Fuentes:</b> <a href=\"{$url}\">Informe de ventas diario</a>",
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
        ], $this->lastSent());

        $conversation = AssistantConversation::query()->sole();
        $this->assertSame(['telegram', self::CHAT, $this->jhon->id], [$conversation->channel, $conversation->telegram_chat_id, $conversation->user_id]);
        $this->assertSame(['text', 'answer'], $conversation->messages()->orderBy('id')->pluck('kind')->all());
    }

    public function test_several_sources_go_one_per_line_and_every_text_is_escaped(): void
    {
        $first = $this->salesCard('Ventas & cartera <diario>');
        $second = $this->salesCard('Pedidos de ventas a SAP B1');
        $this->link($this->jhon);
        $this->provider(
            $this->tool('buscar_conocimiento', ['texto' => 'ventas']),
            $this->text("Usa <b>esto</b> & **aquello** [{$first}, {$second}]."),
        );

        $this->send('¿Qué informe uso?');

        $this->assertSame(
            "Usa &lt;b&gt;esto&lt;/b&gt; &amp; <b>aquello</b>.\n\n<b>Fuentes:</b>\n"
            .'<a href="'.route('assistant.knowledge.show', ['card' => $first]).'">Ventas &amp; cartera &lt;diario&gt;</a>'."\n"
            .'<a href="'.route('assistant.knowledge.show', ['card' => $second]).'">Pedidos de ventas a SAP B1</a>',
            $this->lastSent()['text'],
        );
    }

    public function test_a_long_answer_is_cut_after_the_fourth_paragraph_and_keeps_its_sources(): void
    {
        $key = $this->salesCard('Conectarse a la VPN');
        $this->link($this->jhon);
        $paragraphs = ['Uno.', 'Dos.', 'Tres.', 'Cuatro.', 'Cinco.', 'Seis.'];
        $this->provider(
            $this->tool('buscar_conocimiento', ['texto' => 'vpn']),
            $this->text(implode("\n\n", $paragraphs)." [{$key}]"),
        );

        $this->send('¿Cómo me conecto a la VPN?');

        $conversation = AssistantConversation::query()->sole();
        $this->assertSame(
            "Uno.\n\nDos.\n\nTres.\n\nCuatro.\n\n"
            .'<a href="'.route('assistant.index', ['conversation' => $conversation->id]).'">Ver respuesta completa en el Gestor TI</a>'."\n\n"
            .'<b>Fuentes:</b> <a href="'.route('assistant.knowledge.show', ['card' => $key]).'">Conectarse a la VPN</a>',
            $this->lastSent()['text'],
        );
    }

    public function test_an_answer_over_3500_characters_is_cut_at_the_last_complete_paragraph(): void
    {
        $key = $this->salesCard('Conectarse a la VPN');
        $this->link($this->jhon);
        $first = str_repeat('a', 1990).'.';
        $second = str_repeat('b', 1990).'.';
        $this->provider(
            $this->tool('buscar_conocimiento', ['texto' => 'vpn']),
            $this->text("{$first}\n\n{$second} [{$key}]"),
        );

        $this->send('¿Cómo me conecto a la VPN?');

        $text = $this->lastSent()['text'];
        $this->assertStringStartsWith($first."\n\n<a href=", $text);
        $this->assertStringNotContainsString('bbbb', $text);
        $this->assertStringContainsString('Ver respuesta completa en el Gestor TI</a>', $text);
        $this->assertStringEndsWith('Conectarse a la VPN</a>', $text);
        $this->assertLessThanOrEqual(4096, mb_strlen($text));
    }

    public function test_not_documented_offers_the_unanswered_list_only_to_knowledge_managers(): void
    {
        $reader = $this->reader();
        $this->link($this->jhon);
        $this->link($reader, '5151');
        $nothing = $this->tool('buscar_conocimiento', ['texto' => 'impresora piso 3']);
        $this->provider($nothing, $this->text('No lo tengo documentado.'), $nothing, $this->text('No lo tengo documentado.'));

        $this->send('¿Cuál es la IP de la impresora del piso 3?');
        $manager = $this->lastSent();
        $this->send('¿Cuál es la IP de la impresora del piso 3?', '5151');
        $other = $this->lastSent();

        $text = 'No lo tengo documentado. Registré la pregunta para que TI la documente.';
        $this->assertSame([$text, 'HTML'], [$manager['text'], $manager['parse_mode']]);
        $this->assertSame([[['text' => 'Ver preguntas sin respuesta', 'url' => route('assistant.unanswered.index')]]], $manager['reply_markup']['inline_keyboard']);
        $this->assertSame($text, $other['text']);
        $this->assertArrayNotHasKey('reply_markup', $other);

        $row = UnansweredQuestion::query()->sole();
        $this->assertSame(2, $row->times_asked);
        $this->assertSame(['telegram', 'telegram'], $row->asks()->pluck('channel')->all());
    }

    public function test_unlinked_chats_get_the_help_and_people_without_the_assistant_get_the_refusal(): void
    {
        $noAssistant = $this->user($this->segurex, 'Usuario final', 'Sin asistente');
        $this->link($noAssistant, '6161');

        $this->send('¿Quién recibe el informe de ventas?', '999');
        $this->assertSame(UpdateHandler::helpText(), $this->lastSent()['text']);
        $this->assertStringContainsString('soy Ingenierito,', $this->lastSent()['text']);

        $this->send('¿Quién recibe el informe de ventas?', '6161');
        $this->assertSame(TelegramAssistant::NO_ACCESS, $this->lastSent()['text']);

        $this->assertNoProviderCall();
        $this->assertSame(0, AssistantConversation::query()->count());
        $this->assertSame([], $this->telegram('sendChatAction'));
    }

    public function test_without_a_key_over_the_cap_or_on_provider_failure_everyone_gets_the_same_text(): void
    {
        $this->link($this->jhon);
        $unavailable = 'El asistente no está disponible en este momento, intenta en unos minutos.';

        config(['services.deepseek.api_key' => null]);
        $this->send('Pregunta sin llave');
        $this->assertSame([$unavailable, 'HTML'], [$this->lastSent()['text'], $this->lastSent()['parse_mode']]);
        $this->assertSame([], $this->telegram('sendChatAction'));
        $this->assertSame(0, AssistantConversation::query()->count());

        config(['services.deepseek.api_key' => 'test-deepseek-key']);
        $this->setting('ai.monthly_cost_cap_usd', 1.0);
        AiInteraction::query()->create(['provider' => 'deepseek', 'model' => 'deepseek-chat', 'feature' => 'assistant', 'cost' => 1.5, 'status' => 'ok']);
        $this->send('Pregunta sobre el tope');
        $this->assertSame($unavailable, $this->lastSent()['text']);
        $this->assertSame(0, AssistantConversation::query()->count());

        $this->setting('ai.monthly_cost_cap_usd', 100.0);
        $this->provider(500, 500);
        $this->send('Pregunta con el proveedor caído');
        $this->assertSame($unavailable, $this->lastSent()['text']);
        $this->assertSame(['text', 'error'], AssistantConversation::query()->sole()->messages()->orderBy('id')->pluck('kind')->all());
    }

    public function test_questions_are_limited_per_chat(): void
    {
        config(['services.deepseek.api_key' => null, 'gestor.telegram.assistant_per_minute' => 3]);
        $this->link($this->jhon);

        foreach (range(1, 4) as $i) {
            $this->send("Pregunta {$i}");
        }

        $texts = array_column($this->telegram('sendMessage'), 'text');
        $this->assertCount(4, $texts);
        $this->assertSame(TelegramAssistant::RATE_LIMITED, $texts[3]);
        $this->assertNotSame(TelegramAssistant::RATE_LIMITED, $texts[2]);

        $this->travel(61)->seconds();
        $this->send('Pregunta 5');
        $this->assertNotSame(TelegramAssistant::RATE_LIMITED, $this->lastSent()['text']);
    }

    public function test_the_conversation_is_kept_per_chat_for_thirty_minutes(): void
    {
        $this->link($this->jhon);
        $this->provider($this->text('Uno'), $this->text('Dos'), $this->text('Tres'));

        $this->send('Primera');
        $this->travel(10)->minutes();
        $this->send('Segunda');
        $this->assertSame(1, AssistantConversation::query()->count());

        $this->travel(31)->minutes();
        $this->send('Tercera');
        $this->assertSame(2, AssistantConversation::query()->count());
    }

    public function test_typing_is_repeated_every_four_seconds_while_the_answer_is_generated(): void
    {
        $typing = app(TypingIndicator::class);

        $typing->start(self::CHAT);
        $typing->pulse();
        $this->travel(5)->seconds();
        $typing->pulse();
        $typing->stop();
        $this->travel(5)->seconds();
        $typing->pulse();

        $this->assertCount(2, $this->telegram('sendChatAction'));
    }

    public function test_a_failure_while_answering_still_answers_the_person(): void
    {
        $this->link($this->jhon);
        $this->app->bind(AssistantEngine::class, fn () => throw new \RuntimeException('boom'));

        $this->send('¿Algo?');

        $this->assertSame('El asistente no está disponible en este momento, intenta en unos minutos.', $this->lastSent()['text']);
    }
}
