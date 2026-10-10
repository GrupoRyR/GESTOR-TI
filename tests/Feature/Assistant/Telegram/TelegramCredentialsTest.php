<?php

namespace Tests\Feature\Assistant\Telegram;

use App\Domain\Assistant\CredentialDelivery;
use App\Jobs\DeleteTelegramMessage;
use App\Models\AccessSystem;
use App\Models\AiProduct;
use App\Models\AiVaultItem;
use App\Models\Employee;
use App\Models\EmployeeAccess;
use App\Models\Permission;
use App\Notifications\AssistantCredentialRevealed;
use App\Services\Telegram\TelegramClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

/**
 * Password delivery on Telegram (PROMPT 6.8.2, telegram-format.md §3–§5):
 * a notice, then the value in a SEPARATE protected message deleted after
 * 60 s, sent by the Gestor directly (never through the AI provider),
 * audited with channel `telegram` and alerted like the app reveal.
 */
class TelegramCredentialsTest extends TelegramAssistantTestCase
{
    private const SECRET = 'Tq8#vL2m<Rx4p&';

    private AccessSystem $office;

    private EmployeeAccess $juanOffice;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['services.vault.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Permission::withExclusiveGrant(fn () => $this->jhon->givePermissionTo(Permission::VAULT));

        $this->office = AccessSystem::factory()->create(['name' => 'Office']);
        $juan = Employee::factory()->create([
            'company_id' => $this->segurex->id, 'area_id' => $this->ventas->id,
            'name' => 'Juan Pérez', 'email' => 'juan.perez@segurex.com', 'job_title' => 'Asesor comercial',
        ]);
        $this->juanOffice = EmployeeAccess::factory()->create([
            'employee_id' => $juan->id, 'access_system_id' => $this->office->id,
            'username' => 'juan.perez@segurex.com', 'password' => self::SECRET,
        ]);

        $this->link($this->superAdmin);
    }

    private function askForPassword(string $chatId = self::CHAT): void
    {
        $this->provider(
            $this->tool('entregar_credencial', ['colaborador' => 'Juan Pérez', 'sistema' => 'Office']),
            $this->text('Listo, te la entregué por aparte.'),
        );

        $this->send('Dame la contraseña de Office de Juan Pérez', $chatId);
    }

    private function namesake(): EmployeeAccess
    {
        $other = Employee::factory()->create([
            'company_id' => $this->derivados->id, 'area_id' => $this->derivadosVentas->id,
            'name' => 'Juan Pérez Gómez', 'email' => 'jperez@derivados.com', 'job_title' => 'Auxiliar de bodega',
        ]);

        return EmployeeAccess::factory()->create([
            'employee_id' => $other->id, 'access_system_id' => $this->office->id,
            'username' => 'jperez@derivados.com', 'password' => 'Otra-Clave-77',
        ]);
    }

    private function assertSecretNeverStoredNorSentToTheAi(string $secret = self::SECRET): void
    {
        $this->assertStringNotContainsString($secret, $this->providerRaw());
        $stored = DB::table('assistant_messages')->get()->map(fn ($row) => $row->body.$row->payload)->implode("\n");
        $this->assertStringNotContainsString($secret, $stored);
    }

    public function test_one_match_sends_a_notice_then_the_password_in_a_separate_message_deleted_after_60_seconds(): void
    {
        $this->askForPassword();

        $sent = $this->telegram('sendMessage');
        $this->assertCount(2, $sent);
        $this->assertSame('Listo. Te envío la contraseña de Office de Juan Pérez en un mensaje aparte.', $sent[0]['text']);
        $this->assertArrayNotHasKey('protect_content', $sent[0]);

        $this->assertSame([
            'chat_id' => self::CHAT,
            'text' => "<b>Office · Juan Pérez</b>\n{$this->ventas->name} · {$this->segurex->short_name}\nUsuario: juan.perez@segurex.com\n"
                ."Contraseña: <code>Tq8#vL2m&lt;Rx4p&amp;</code>\nEste mensaje se borra en 60 s\n<i>No pasó por la IA · quedó en la auditoría</i>",
            'protect_content' => true,
            'parse_mode' => 'HTML',
        ], $sent[1]);

        Queue::assertPushed(DeleteTelegramMessage::class, fn (DeleteTelegramMessage $job) => $job->chatId === self::CHAT
            && $job->messageId === $this->nextMessageId
            && $job->fallbackText === 'Mensaje borrado por seguridad'
            && $job->accessId === $this->juanOffice->id
            && now()->addSeconds(60)->equalTo($job->delay));

        $this->assertSecretNeverStoredNorSentToTheAi();

        $reveal = Activity::query()->where('event', 'credential_revealed')->sole();
        $this->assertSame(['telegram', $this->superAdmin->id], [$reveal->properties['channel'], $reveal->causer_id]);
        $this->assertSame('telegram', Activity::query()->where('event', 'credential_delivered')->sole()->properties['channel']);

        Notification::assertSentTo($this->jhon, AssistantCredentialRevealed::class, fn (AssistantCredentialRevealed $n) => $n->personName === 'Leonardo Herrera'
            && $n->employeeName === 'Juan Pérez' && $n->systemName === 'Office' && $n->channel === 'Telegram');
    }

    public function test_the_delete_job_deletes_or_else_edits_the_message_and_audits_the_outcome(): void
    {
        (new DeleteTelegramMessage(self::CHAT, 77, 'Mensaje borrado por seguridad', $this->juanOffice->id, $this->superAdmin->id))->handle(app(TelegramClient::class));

        $this->assertSame([['chat_id' => self::CHAT, 'message_id' => 77]], $this->telegram('deleteMessage'));
        $this->assertSame([], $this->telegram('editMessageText'));
        $this->assertSame('deleted', Activity::query()->where('event', 'credential_message_deleted')->sole()->properties['outcome']);
    }

    public function test_a_failed_delete_falls_back_to_editing_the_message(): void
    {
        $this->failing = ['deleteMessage'];

        (new DeleteTelegramMessage(self::CHAT, 77, 'Mensaje borrado por seguridad', $this->juanOffice->id, $this->superAdmin->id))->handle(app(TelegramClient::class));

        $this->assertSame('Mensaje borrado por seguridad', $this->telegram('editMessageText')[0]['text']);
        $this->assertSame('edited', Activity::query()->where('event', 'credential_message_deleted')->sole()->properties['outcome']);
    }

    public function test_without_both_permissions_the_refusal_never_says_whether_it_exists(): void
    {
        $this->link($this->jhon, '5151');
        $this->askForPassword('5151');

        $this->assertSame(['No puedo entregar contraseñas. Pídela a TI.', 'HTML'], [$this->lastSent()['text'], $this->lastSent()['parse_mode']]);
        $this->assertCount(1, $this->telegram('sendMessage'));
        Queue::assertNotPushed(DeleteTelegramMessage::class);
        $this->assertSame(0, Activity::query()->where('event', 'credential_revealed')->count());
    }

    public function test_several_people_get_choice_buttons_and_the_tap_delivers_the_chosen_one(): void
    {
        $other = $this->namesake();
        $this->askForPassword();

        $choice = $this->lastSent();
        $this->assertSame('Hay dos personas con ese nombre. ¿De cuál?', $choice['text']);
        $buttons = $this->buttons($choice);
        $label = CredentialDelivery::personLine($other->employee()->withoutGlobalScopes()->firstOrFail());
        $this->assertStringStartsWith('Juan Pérez Gómez · Auxiliar de bodega · ', $label);
        $this->assertEqualsCanonicalizing([CredentialDelivery::personLine($this->juanOffice->employee), $label], array_keys($buttons));
        foreach ($buttons as $button) {
            $this->assertStringStartsWith('cred:pick:', $button['callback_data']);
            $this->assertLessThanOrEqual(64, strlen($button['callback_data']));
            $this->assertStringNotContainsString('Juan', $button['callback_data']);
        }
        Queue::assertNotPushed(DeleteTelegramMessage::class);

        $this->press($buttons[$label]['callback_data'], $this->nextMessageId);

        $this->assertStringContainsString('<code>Otra-Clave-77</code>', $this->lastSent()['text']);
        $this->assertTrue($this->lastSent()['protect_content']);
        Queue::assertPushed(DeleteTelegramMessage::class, fn (DeleteTelegramMessage $job) => $job->accessId === $other->id);
        $this->assertSame($other->id, Activity::query()->where('event', 'credential_revealed')->sole()->subject_id);
        $this->assertSecretNeverStoredNorSentToTheAi('Otra-Clave-77');

        // Answered once.
        $this->press($buttons[$label]['callback_data'], $this->nextMessageId);
        $this->assertSame('Esta pregunta ya se respondió.', $this->lastCallbackAnswer());
        $this->assertSame(1, Activity::query()->where('event', 'credential_revealed')->count());
    }

    public function test_choice_taps_are_signed_bound_to_the_chat_short_lived_and_rechecked(): void
    {
        $this->namesake();
        $this->askForPassword();
        $data = array_values($this->buttons($this->lastSent()))[0]['callback_data'];

        $this->press(substr($data, 0, -2).'zz');
        $this->assertSame('Esta acción ya no está disponible.', $this->lastCallbackAnswer());

        $this->link($this->jhon, '5151');
        $this->press($data, 900, '5151');
        $this->assertSame('Esta acción ya no está disponible.', $this->lastCallbackAnswer());

        $this->superAdmin->syncRoles([]);
        $this->superAdmin->syncPermissions(['assistant.use']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->press($data);
        $this->assertSame('No puedo entregar contraseñas. Pídela a TI.', $this->lastCallbackAnswer());

        $this->assertSame(0, Activity::query()->where('event', 'credential_revealed')->count());
        Queue::assertNotPushed(DeleteTelegramMessage::class);
    }

    public function test_an_expired_choice_is_not_delivered(): void
    {
        $this->namesake();
        $this->askForPassword();
        $data = array_values($this->buttons($this->lastSent()))[0]['callback_data'];

        $this->travel(11)->minutes();
        $this->press($data);

        $this->assertSame('Esta acción ya no está disponible.', $this->lastCallbackAnswer());
        $this->assertSame(0, Activity::query()->where('event', 'credential_revealed')->count());
    }

    public function test_vault_items_only_get_a_link_to_the_vault(): void
    {
        $product = AiProduct::factory()->create(['name' => 'Gestor TI']);
        $item = new AiVaultItem(['name' => 'DEEPSEEK_API_KEY', 'ai_product_id' => $product->id, 'environment' => 'production', 'type' => 'api_key']);
        $item->setSecret('sk-boveda-no-sale');
        $item->save();
        $this->provider(
            $this->tool('entregar_credencial', ['colaborador' => 'Gestor TI', 'sistema' => 'DeepSeek']),
            $this->text('Está en la bóveda.'),
        );

        $this->send('Dame la llave de API de DeepSeek');

        $sent = $this->lastSent();
        $this->assertSame('Esa credencial está en la bóveda del proyecto. Ábrela ahí; te pedirá confirmar tu identidad.', $sent['text']);
        $this->assertSame([[['text' => 'Abrir en la bóveda', 'url' => route('ai.vault.index', ['item' => $item->id])]]], $sent['reply_markup']['inline_keyboard']);
        $this->assertStringNotContainsString('sk-boveda-no-sale', Http::recorded()->map(fn (array $pair) => $pair[0]->body())->implode("\n"));
    }
}
