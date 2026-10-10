<?php

namespace Tests\Feature\Ai\Costs;

use App\Models\AiProject;
use App\Models\AiTimeEntry;
use App\Services\Telegram\TelegramClient;
use App\Telegram\CallbackRegistry;
use App\Telegram\UpdateHandler;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/**
 * `/horas GIA-123 2 conexión con SAP` (or a project key P-n) from a linked
 * private chat: an entry with origin telegram, answered in Spanish.
 */
class TelegramHoursTest extends CostsTestCase
{
    private AiProject $p;

    private int $updateId = 7000;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
        Notification::fake();

        $this->p = $this->activeProject(100);
        $this->linkTelegram($this->jhon, '555');
        $this->linkTelegram($this->paola, '777');
    }

    private function say(string $chatId, string $text): void
    {
        $this->updateId++;

        (new UpdateHandler([
            'update_id' => $this->updateId,
            'message' => ['message_id' => 10, 'chat' => ['id' => (int) $chatId, 'type' => 'private'], 'from' => ['id' => (int) $chatId], 'text' => $text],
        ]))->handle(app(TelegramClient::class), app(CallbackRegistry::class));
    }

    private function assertReplied(string $chatId, string $needle): void
    {
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/sendMessage')
            && (string) $r['chat_id'] === $chatId
            && str_contains((string) $r['text'], $needle));
    }

    public function test_hours_by_request_key_are_logged(): void
    {
        $request = $this->originRequest($this->p);

        $this->say('555', "/horas {$request->key} 2 conexión con SAP");

        $entry = AiTimeEntry::query()->sole();
        $this->assertSame('telegram', $entry->origin);
        $this->assertSame('2.00', $entry->hours);
        $this->assertSame('conexión con SAP', $entry->note);
        $this->assertSame('2026-10-07', $entry->date->toDateString());
        $this->assertSame($this->p->id, $entry->ai_project_id);
        $this->assertSame($this->jhon->id, $entry->user_id);
        $this->assertReplied('555', 'Listo');
        $this->assertReplied('555', $this->p->key);
    }

    public function test_project_keys_lowercase_and_decimal_commas_are_accepted(): void
    {
        $this->say('555', '/horas '.strtolower($this->p->key).' 1,5 reunión de seguimiento');

        $this->assertSame('1.50', AiTimeEntry::query()->sole()->hours);
    }

    public function test_errors_are_explained_and_nothing_is_logged(): void
    {
        $request = $this->originRequest($this->p);
        $unlinkedRequest = $this->request();

        $this->say('555', '/horas');
        $this->assertReplied('555', '/horas GIA-123 2 conexión con SAP');

        $this->say('555', "/horas {$request->key} dos conexión");
        $this->assertReplied('555', 'cuántas horas');

        $this->say('555', "/horas {$request->key} 17 algo");
        $this->assertReplied('555', 'entre');

        $this->say('555', "/horas {$request->key} 2");
        $this->assertReplied('555', 'qué hiciste');

        $this->say('555', '/horas GIA-99999 2 algo');
        $this->assertReplied('555', 'No encontré GIA-99999');

        $this->say('555', "/horas {$unlinkedRequest->key} 2 algo");
        $this->assertReplied('555', 'todavía no es un proyecto');

        $this->say('777', "/horas {$request->key} 2 algo");
        $this->assertReplied('777', 'No puedes cargar horas');

        $this->say('999', "/horas {$request->key} 2 algo");
        $this->assertReplied('999', 'Vincular Telegram');

        $this->assertSame(0, AiTimeEntry::query()->count());
    }
}
