<?php

namespace Tests\Feature\Ai\Triage;

use App\Enums\Ai\ApprovalChannel;
use App\Enums\Ai\RequestStatus;
use App\Events\RequestApproved;
use App\Models\AiRequest;
use App\Models\AiRequestApproval;
use App\Services\Telegram\TelegramClient;
use App\Telegram\CallbackRegistry;
use App\Telegram\Handlers\ApprovalCallbackHandler;
use App\Telegram\UpdateHandler;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/**
 * ✅ Aprobar / ❌ Rechazar from Telegram (CallbackRegistry prefix `ai-approval`).
 */
class TelegramApprovalTest extends TriageTestCase
{
    private AiRequest $pending;

    private AiRequestApproval $row;

    private int $updateId = 1000;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.telegram.bot_token' => 'test-token']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);
        Notification::fake();

        $this->linkTelegram($this->edward, '555');
        $this->linkTelegram($this->paola, '777');
        $this->pending = $this->evaluated();
        $this->sendToApproval($this->pending, ['approver_ids' => [$this->edward->id]]);
        $this->row = $this->pending->approvals()->where('user_id', $this->edward->id)->sole();
    }

    public function test_the_prefix_is_registered_and_the_data_fits_telegram(): void
    {
        $this->assertContains('ai-approval', app(CallbackRegistry::class)->prefixes());

        $data = ApprovalCallbackHandler::data($this->row->id, 'approve');
        $this->assertLessThanOrEqual(64, strlen($data));
        $this->assertStringStartsWith('ai-approval:', $data);
    }

    public function test_approve_button_records_the_decision_answers_and_edits_the_message(): void
    {
        Event::fake([RequestApproved::class]);

        $this->press('555', ApprovalCallbackHandler::data($this->row->id, 'approve'));

        $this->assertSame(RequestStatus::Approved, $this->pending->fresh()->status);
        $this->assertSame(ApprovalChannel::Telegram, $this->row->fresh()->channel);
        $this->assertNotNull($this->row->fresh()->decided_at);
        Event::assertDispatched(RequestApproved::class);

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/answerCallbackQuery') && $r['callback_query_id'] === 'cb-'.$this->updateId);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/editMessageText')
            && $r['chat_id'] === '555'
            && $r['message_id'] === 91
            && str_contains($r['text'], 'Aprobaste'));
    }

    public function test_a_tampered_or_foreign_button_is_refused(): void
    {
        $this->press('555', 'ai-approval:'.$this->row->id.':a:forged0signature');
        $this->assertSame(RequestStatus::PendingApproval, $this->pending->fresh()->status);

        // Paola is linked but is not the approver of this row.
        $this->press('777', ApprovalCallbackHandler::data($this->row->id, 'approve'));
        $this->assertSame(RequestStatus::PendingApproval, $this->pending->fresh()->status);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/answerCallbackQuery') && str_contains((string) ($r['text'] ?? ''), 'no es tuya'));
        Http::assertNotSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/editMessageText'));
    }

    public function test_an_already_decided_approval_is_idempotent(): void
    {
        $this->press('555', ApprovalCallbackHandler::data($this->row->id, 'approve'));
        $decidedAt = $this->row->fresh()->decided_at;

        $this->press('555', ApprovalCallbackHandler::data($this->row->id, 'reject'));

        $this->assertSame(RequestStatus::Approved, $this->pending->fresh()->status);
        $this->assertEquals($decidedAt, $this->row->fresh()->decided_at);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/answerCallbackQuery') && str_contains((string) ($r['text'] ?? ''), 'ya estaba decidida'));
    }

    public function test_reject_asks_for_the_reason_in_a_follow_up_message(): void
    {
        $this->press('555', ApprovalCallbackHandler::data($this->row->id, 'reject'));

        $this->assertSame(RequestStatus::PendingApproval, $this->pending->fresh()->status);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/sendMessage') && str_contains($r['text'], 'motivo'));

        $this->message('555', 'Primero cerremos P-07');

        $this->assertSame(RequestStatus::Rejected, $this->pending->fresh()->status);
        $this->assertSame('Primero cerremos P-07', $this->row->fresh()->comment);
        $this->assertSame(ApprovalChannel::Telegram, $this->row->fresh()->channel);
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/editMessageText') && str_contains($r['text'], 'Rechazaste'));
    }

    public function test_the_rejection_reason_wins_over_the_assistant(): void
    {
        // Edward may also ask the Asistente TI: the reason must not reach it.
        $this->edward->givePermissionTo('assistant.use');
        config(['services.deepseek.api_key' => 'test-deepseek-key']);
        Http::preventStrayRequests();

        $this->press('555', ApprovalCallbackHandler::data($this->row->id, 'reject'));
        $this->message('555', '¿Por qué no cerramos P-07 primero?');

        $this->assertSame(RequestStatus::Rejected, $this->pending->fresh()->status);
        $this->assertSame('¿Por qué no cerramos P-07 primero?', $this->row->fresh()->comment);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'deepseek'));
        Http::assertNotSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/sendChatAction'));
    }

    public function test_cancel_keeps_the_approval_pending(): void
    {
        $this->press('555', ApprovalCallbackHandler::data($this->row->id, 'reject'));
        $this->message('555', '/cancelar');
        $this->message('555', 'Un texto cualquiera');

        $this->assertSame(RequestStatus::PendingApproval, $this->pending->fresh()->status);
    }

    private function press(string $chatId, string $data): void
    {
        $this->updateId++;

        (new UpdateHandler([
            'update_id' => $this->updateId,
            'callback_query' => [
                'id' => 'cb-'.$this->updateId,
                'from' => ['id' => (int) $chatId],
                'data' => $data,
                'message' => ['message_id' => 91, 'chat' => ['id' => (int) $chatId, 'type' => 'private'], 'text' => 'GIA-1 espera tu aprobación'],
            ],
        ]))->handle(app(TelegramClient::class), app(CallbackRegistry::class));
    }

    private function message(string $chatId, string $text): void
    {
        $this->updateId++;

        (new UpdateHandler([
            'update_id' => $this->updateId,
            'message' => ['message_id' => 95, 'chat' => ['id' => (int) $chatId, 'type' => 'private'], 'from' => ['id' => (int) $chatId], 'text' => $text],
        ]))->handle(app(TelegramClient::class), app(CallbackRegistry::class));
    }
}
