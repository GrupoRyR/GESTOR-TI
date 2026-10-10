<?php

namespace Tests\Feature\Assistant\Chat;

use App\Models\AccessSystem;
use App\Models\AiProduct;
use App\Models\AiVaultItem;
use App\Models\AssistantMessage;
use App\Models\Employee;
use App\Models\EmployeeAccess;
use App\Models\Permission;
use App\Models\User;
use App\Notifications\AssistantCredentialRevealed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

/**
 * `entregar_credencial` (PROMPT 6.8.2, 6.8.4): the model only says WHICH
 * credential; the value never reaches the provider nor a chat message. It
 * exists once, in `revealed_credential`, after "Mostrar".
 */
class CredentialDeliveryTest extends ChatTestCase
{
    private const SECRET = 'Tq8#vL2m-Rx4p-Secreta';

    private AccessSystem $office;

    private EmployeeAccess $juanOffice;

    protected function setUp(): void
    {
        parent::setUp();

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

    private function askForPassword(?User $user = null): AssistantMessage
    {
        $this->provider(
            $this->tool('entregar_credencial', ['colaborador' => 'Juan Pérez', 'sistema' => 'Office']),
            $this->text('Listo, te la entregué por aparte.'),
        );

        $this->ask($user ?? $this->superAdmin, 'Dame la contraseña de Office de Juan Pérez')->assertRedirect();

        return AssistantMessage::query()->latest('id')->firstOrFail();
    }

    private function assertSecretNeverStored(): void
    {
        $this->assertStringNotContainsString(self::SECRET, $this->sentRaw());
        $stored = DB::table('assistant_messages')->get()->map(fn ($row) => $row->body.$row->payload)->implode("\n");
        $this->assertStringNotContainsString(self::SECRET, $stored);
    }

    public function test_without_both_credential_permissions_the_answer_is_refused(): void
    {
        $call = $this->tool('entregar_credencial', ['colaborador' => 'Juan Pérez', 'sistema' => 'Office']);
        $this->provider($call, $this->text('No puedo.'), $call, $this->text('No puedo.'));

        foreach ([[$this->reader(), 1], [$this->jhon, 3]] as [$user, $request]) {
            $this->ask($user, 'Dame la contraseña de Office de Juan Pérez')->assertRedirect();
            $message = AssistantMessage::query()->latest('id')->firstOrFail();

            $this->assertSame(['assistant', 'refused', 'No puedo entregar contraseñas. Pídela a TI.'], [$message->role, $message->kind, $message->body]);
            $this->assertStringContainsString('rechazada', collect($this->sentBodies()[$request]['messages'])->firstWhere('role', 'tool')['content']);
        }

        $this->assertSame(0, AssistantMessage::query()->where('kind', 'credential_notice')->count());
        $this->assertSecretNeverStored();
    }

    public function test_exactly_one_match_delivers_a_notice_and_the_model_only_hears_delivered(): void
    {
        $notice = $this->askForPassword();

        $this->assertSame(['system', 'credential_notice'], [$notice->role, $notice->kind]);
        $this->assertSame($this->juanOffice->id, $notice->data('access_id'));
        $this->assertSame('juan.perez@segurex.com', $notice->data('username'));
        $this->assertSame('Office', $notice->data('system'));

        $toolResult = collect($this->sentBodies()[1]['messages'])->firstWhere('role', 'tool')['content'];
        $this->assertStringContainsString('credencial entregada', $toolResult);
        $this->assertStringNotContainsString('juan.perez@segurex.com', $toolResult);
        $this->assertSecretNeverStored();

        // The notice is the last word: no AI text after it.
        $this->assertSame(['text', 'credential_notice'], AssistantMessage::query()->orderBy('id')->pluck('kind')->all());
        $this->assertSame(1, Activity::query()->where('event', 'credential_delivered')->count());

        $this->actingAs($this->superAdmin)
            ->get(route('assistant.index', ['conversation' => $notice->conversation_id]))
            ->assertDontSee(self::SECRET)
            ->assertInertia(fn (Assert $page) => $page
                ->where('conversation.messages.1', [
                    'id' => $notice->id,
                    'at' => $notice->created_at->toIso8601String(),
                    'role' => 'system',
                    'kind' => 'credential_notice',
                    'credential_id' => $this->juanOffice->id,
                    'system' => 'Office',
                    'person' => [
                        'id' => $this->juanOffice->employee_id,
                        'name' => 'Juan Pérez',
                        'job_title' => 'Asesor comercial',
                        'area' => 'Ventas',
                        'company_id' => $this->segurex->id,
                        'company_name' => $this->segurex->short_name,
                    ],
                    'username' => 'juan.perez@segurex.com',
                    'reveal_seconds' => 30,
                ])
                ->where('revealed_credential', null)
            );
    }

    public function test_no_match_answers_not_found(): void
    {
        $this->provider(
            $this->tool('entregar_credencial', ['colaborador' => 'Nadie Existe', 'sistema' => 'Office']),
            $this->text('No encontré esa credencial.'),
        );

        $this->ask($this->superAdmin, 'Dame la contraseña de Office de Nadie Existe');

        $this->assertStringContainsString('no encontrada', collect($this->sentBodies()[1]['messages'])->firstWhere('role', 'tool')['content']);
        $this->assertSame(['text', 'answer'], AssistantMessage::query()->orderBy('id')->pluck('kind')->all());
    }

    public function test_several_matches_ask_to_choose_and_the_pick_delivers(): void
    {
        $other = $this->namesake();
        $choice = $this->askForPassword();

        $this->assertSame(['assistant', 'person_choice'], [$choice->role, $choice->kind]);
        $this->assertSame('Hay dos personas con ese nombre. ¿De cuál?', $choice->body);
        $this->assertStringContainsString('escoger', collect($this->sentBodies()[1]['messages'])->firstWhere('role', 'tool')['content']);

        $this->actingAs($this->superAdmin)->get(route('assistant.index', ['conversation' => $choice->conversation_id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('conversation.messages.1.kind', 'person_choice')
                ->where('conversation.messages.1.resolved_option_id', null)
                ->where('conversation.messages.1.options.1', [
                    'id' => $other->employee_id, 'name' => 'Juan Pérez Gómez', 'job_title' => 'Auxiliar de bodega', 'area' => 'Ventas',
                    'company_id' => $this->derivados->id, 'company_name' => $this->derivados->short_name,
                ])
            );

        $this->actingAs($this->superAdmin)
            ->post(route('assistant.people.pick', $choice), ['option' => $this->juanOffice->employee_id])
            ->assertRedirect(route('assistant.index', ['conversation' => $choice->conversation_id]));

        $this->assertSame(['text', 'person_choice', 'text', 'credential_notice'], AssistantMessage::query()->orderBy('id')->pluck('kind')->all());
        $this->assertSame('Juan Pérez · Asesor comercial · Ventas · '.$this->segurex->short_name, AssistantMessage::query()->where('kind', 'text')->latest('id')->first()->body);
        $this->assertSame($this->juanOffice->id, AssistantMessage::query()->latest('id')->first()->data('access_id'));
        $this->assertSame($this->juanOffice->employee_id, $choice->fresh()->data('resolved_option_id'));

        // Answered once.
        $this->actingAs($this->superAdmin)->post(route('assistant.people.pick', $choice), ['option' => $other->employee_id])->assertSessionHas('error');
        $this->assertSame(1, AssistantMessage::query()->where('kind', 'credential_notice')->count());
        $this->assertSecretNeverStored();
    }

    public function test_none_of_them_delivers_nothing(): void
    {
        $this->namesake();
        $choice = $this->askForPassword();

        $this->actingAs($this->superAdmin)->post(route('assistant.people.pick', $choice), ['option' => null])->assertRedirect();

        $this->assertSame(['text', 'person_choice', 'text', 'answer'], AssistantMessage::query()->orderBy('id')->pluck('kind')->all());
        $this->assertSame('Ninguno de los dos', AssistantMessage::query()->where('kind', 'text')->latest('id')->first()->body);
        $this->assertSame(0, AssistantMessage::query()->where('kind', 'credential_notice')->count());
    }

    public function test_vault_credentials_are_never_delivered_only_linked(): void
    {
        $product = AiProduct::factory()->create(['name' => 'Gestor TI']);
        $item = new AiVaultItem(['name' => 'DEEPSEEK_API_KEY', 'ai_product_id' => $product->id, 'environment' => 'production', 'type' => 'api_key']);
        $item->setSecret('sk-boveda-no-sale');
        $item->save();
        $this->provider(
            $this->tool('entregar_credencial', ['colaborador' => 'Gestor TI', 'sistema' => 'DeepSeek']),
            $this->text('Está en la bóveda.'),
        );

        $this->ask($this->superAdmin, 'Dame la llave de API de DeepSeek');

        $link = AssistantMessage::query()->latest('id')->first();
        $this->assertSame('vault_link', $link->kind);
        $this->assertSame('Esa credencial está en la bóveda del proyecto. Ábrela ahí; te pedirá confirmar tu identidad.', $link->body);
        $this->assertStringNotContainsString('sk-boveda-no-sale', $this->sentRaw());
        $this->actingAs($this->superAdmin)->get(route('assistant.index', ['conversation' => $link->conversation_id]))
            ->assertInertia(fn (Assert $page) => $page->where('conversation.messages.1.item', [
                'id' => $item->id, 'name' => 'DEEPSEEK_API_KEY', 'product' => 'Gestor TI', 'environment_label' => 'producción',
            ]));
    }

    public function test_reveal_returns_the_value_once_encrypted_uncached_logged_and_alerted(): void
    {
        $notice = $this->askForPassword();

        $response = $this->actingAs($this->superAdmin)
            ->withHeaders($this->partial('revealed_credential'))
            ->post(route('assistant.credentials.reveal', $notice))
            ->assertOk()
            ->assertJsonPath('component', 'Assistant/Chat')
            ->assertJsonPath('props.revealed_credential', ['message_id' => $notice->id, 'value' => self::SECRET, 'expires_in_seconds' => 30])
            ->assertJsonPath('encryptHistory', true)
            ->assertJsonPath('url', route('assistant.index', ['conversation' => $notice->conversation_id], false))
            ->assertJsonMissingPath('props.conversations');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));

        $log = Activity::query()->where('event', 'credential_revealed')->sole();
        $this->assertSame('assistant', $log->properties['channel']);
        $this->assertSame($this->superAdmin->id, $log->causer_id);
        Notification::assertSentTo($this->jhon, AssistantCredentialRevealed::class, fn (AssistantCredentialRevealed $notice) => str_contains($notice->title(), 'Office')
            && ! str_contains($notice->title().$notice->body(), self::SECRET)
            && in_array('telegram', config('notifications.events')[$notice->eventType()]['required_channels'], true));

        // Only that response carries it.
        $this->flushHeaders();
        $this->actingAs($this->superAdmin)->get(route('assistant.index', ['conversation' => $notice->conversation_id]))
            ->assertDontSee(self::SECRET)
            ->assertInertia(fn (Assert $page) => $page->where('revealed_credential', null));
        $this->assertSecretNeverStored();
    }

    public function test_reveal_is_only_for_the_owner_with_permission_within_the_window(): void
    {
        $notice = $this->askForPassword();

        Permission::withExclusiveGrant(fn () => $this->jhon->givePermissionTo(['credentials.view', 'assistant.credentials']));
        $this->actingAs($this->jhon)->post(route('assistant.credentials.reveal', $notice))->assertNotFound();

        $this->superAdmin->roles()->first()->revokePermissionTo('credentials.view');
        $this->superAdmin->unsetRelation('roles')->forgetCachedPermissions();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($this->superAdmin->fresh())->post(route('assistant.credentials.reveal', $notice))->assertForbidden();
        $this->superAdmin->roles()->first()->givePermissionTo('credentials.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->travel(11)->minutes();
        $this->actingAs($this->superAdmin->fresh())->post(route('assistant.credentials.reveal', $notice))
            ->assertRedirect(route('assistant.index', ['conversation' => $notice->conversation_id]))
            ->assertSessionHas('error', 'Esta entrega venció. Pide la contraseña otra vez en el chat.');

        $this->assertSame(0, Activity::query()->where('event', 'credential_revealed')->count());
        $choice = AssistantMessage::query()->where('kind', 'text')->first();
        $this->actingAs($this->superAdmin)->post(route('assistant.credentials.reveal', $choice))->assertNotFound();
    }

    public function test_copied_is_logged_without_the_value_and_reloads_the_conversation(): void
    {
        $notice = $this->askForPassword();

        $this->actingAs($this->superAdmin)
            ->withHeaders($this->partial('conversation'))
            ->post(route('assistant.credentials.copied', $notice))
            ->assertOk()
            ->assertJsonPath('props.conversation.id', $notice->conversation_id)
            ->assertJsonMissingPath('props.revealed_credential');

        $log = Activity::query()->where('event', 'credential_copied')->sole();
        $this->assertSame('assistant', $log->properties['channel']);
        $this->assertStringNotContainsString(self::SECRET, json_encode($log->properties));
    }
}
