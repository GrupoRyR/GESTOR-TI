<?php

namespace Tests\Feature\Profile;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\McpCall;
use App\Models\McpToken;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\Feature\Mcp\McpTestCase;

/**
 * "Conector para Claude" (`Profile/Mcp`, types/pages/profile-mcp.ts):
 * personal tokens shown once, revocation, activity per token, connection
 * test and the no-permission state.
 */
class McpConnectorPageTest extends McpTestCase
{
    private const TOKEN_KEYS = ['id', 'name', 'scope', 'last4', 'created_at', 'last_used_at', 'last_client', 'expires_at', 'revoked_at'];

    private const CALL_KEYS = ['id', 'tool', 'summary', 'at', 'token', 'result', 'reason'];

    private function assertNoStore(TestResponse $response): void
    {
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function logCall(McpToken $token, array $attributes = []): McpCall
    {
        return McpCall::query()->create([
            'user_id' => $token->user_id,
            'mcp_token_id' => $token->id,
            'tool' => 'ver_solicitud',
            'target' => 'GIA-139',
            'result' => McpCall::RESULT_OK,
            ...$attributes,
        ]);
    }

    private function partial(User $user, string $url, string $only): TestResponse
    {
        $response = $this->actingAs($user)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
                'X-Inertia-Partial-Component' => 'Profile/Mcp',
                'X-Inertia-Partial-Data' => $only,
            ])
            ->get($url);
        $this->flushHeaders();

        return $response;
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('profile.mcp'))->assertRedirect(route('login'));
    }

    public function test_page_returns_exact_props_for_a_holder(): void
    {
        ['token' => $laptop] = $this->personalToken($this->jhon, McpToken::SCOPE_READ_WRITE, 90, 'Claude · portátil');
        ['token' => $old] = $this->personalToken($this->jhon, McpToken::SCOPE_READ, 30, 'Prueba');
        $old->forceFill(['revoked_at' => now(), 'revoked_by' => $this->jhon->id])->save();
        $laptop->forceFill(['last_used_at' => now(), 'last_client' => 'claude-code'])->save();
        $this->personalToken($this->paola, McpToken::SCOPE_READ, 30, 'Ajeno');

        $response = $this->actingAs($this->jhon)->get(route('profile.mcp'));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Profile/Mcp')
            ->where('server', ['url' => url('/mcp'), 'active' => true])
            ->has('tokens', 2)
            ->has('tokens.0', fn (Assert $token) => $token
                ->where('id', $laptop->id)
                ->where('name', 'Claude · portátil')
                ->where('scope', 'read_write')
                ->where('last4', $laptop->last_four)
                ->where('created_at', $laptop->created_at->toIso8601String())
                ->where('last_used_at', $laptop->last_used_at->toIso8601String())
                ->where('last_client', 'claude-code')
                ->where('expires_at', now('America/Bogota')->addDays(90)->toDateString())
                ->where('revoked_at', null))
            ->where('tokens.1.id', $old->id)
            ->where('tokens.1.revoked_at', $old->revoked_at->toIso8601String())
            ->where('connection_test', null)
            ->where('created_token', null)
            ->where('selected_token', null)
            ->where('activity', null)
            ->where('mcp_holders', ['Jhon Castro'])
            ->where('now', fn ($now) => is_string($now) && $now !== '')
        );
        $this->assertSame(self::TOKEN_KEYS, array_keys($response->viewData('page')['props']['tokens'][0]));
        $this->assertTrue($response->viewData('page')['encryptHistory']);
        $this->assertNoStore($response);
    }

    public function test_store_shows_the_value_once_and_keeps_only_its_hash(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event->message.json_encode($event->context);
        });

        $store = $this->actingAs($this->jhon)->post(route('profile.mcp.tokens.store'), [
            'name' => '  Claude Code · oficina ',
            'scope' => 'read',
            'expires_in_days' => 30,
        ]);
        $store->assertRedirect(route('profile.mcp'));
        $this->assertNoStore($store);

        $page = $this->actingAs($this->jhon)->get(route('profile.mcp'));
        $page->assertOk();
        $this->assertNoStore($page);
        $this->assertTrue($page->viewData('page')['encryptHistory']);

        $created = $page->viewData('page')['props']['created_token'];
        $token = McpToken::query()->sole();
        $this->assertSame(['id', 'name', 'value', 'scope', 'expires_at'], array_keys($created));
        $this->assertSame([$token->id, 'Claude Code · oficina', 'read', now('America/Bogota')->addDays(30)->toDateString()], [
            $created['id'], $created['name'], $created['scope'], $created['expires_at'],
        ]);
        $this->assertMatchesRegularExpression('/^gti_mcp_[A-Za-z0-9_-]{40}$/', $created['value']);
        $this->assertSame(hash('sha256', $created['value']), $token->token_hash);
        $this->assertSame(substr($created['value'], -4), $token->last_four);
        $this->assertSame($this->jhon->id, $token->user_id);
        $this->assertStringNotContainsString($created['value'], json_encode($token->getAttributes()));

        // Only that one response carries it.
        $again = $this->actingAs($this->jhon)->get(route('profile.mcp'));
        $again->assertInertia(fn (Assert $page) => $page->where('created_token', null)->where('tokens.0.last4', $token->last_four));
        $this->assertStringNotContainsString($created['value'], (string) $again->getContent());

        foreach ([implode("\n", $logged), Activity::all()->toJson()] as $stored) {
            $this->assertStringNotContainsString(McpToken::PREFIX, $stored);
        }
        $audit = Activity::query()->where('event', 'mcp_personal_token_created')->sole();
        $this->assertSame(['auth', $this->jhon->id, $token->id], [$audit->log_name, $audit->causer_id, $audit->subject_id]);
    }

    public function test_store_validates_name_scope_and_expiry(): void
    {
        $this->personalToken($this->jhon, McpToken::SCOPE_READ, 90, 'Claude · portátil');
        ['token' => $revoked] = $this->personalToken($this->jhon, McpToken::SCOPE_READ, 90, 'Prueba');
        $revoked->forceFill(['revoked_at' => now()])->save();
        $this->personalToken($this->paola, McpToken::SCOPE_READ, 90, 'Claude · casa');

        $this->actingAs($this->jhon)->post(route('profile.mcp.tokens.store'), ['name' => '', 'scope' => 'read', 'expires_in_days' => 90])
            ->assertSessionHasErrors('name');
        $this->actingAs($this->jhon)->post(route('profile.mcp.tokens.store'), ['name' => str_repeat('a', 61), 'scope' => 'read', 'expires_in_days' => 90])
            ->assertSessionHasErrors('name');
        $this->actingAs($this->jhon)->post(route('profile.mcp.tokens.store'), ['name' => 'claude · PORTÁTIL', 'scope' => 'read', 'expires_in_days' => 90])
            ->assertSessionHasErrors(['name' => 'Ya tienes un token activo con ese nombre.']);
        $this->actingAs($this->jhon)->post(route('profile.mcp.tokens.store'), ['name' => 'Otro', 'scope' => 'admin', 'expires_in_days' => 45])
            ->assertSessionHasErrors(['scope', 'expires_in_days']);
        $this->assertSame(3, McpToken::query()->count());

        // A revoked name and another person's name are free.
        $this->actingAs($this->jhon)->post(route('profile.mcp.tokens.store'), ['name' => 'Prueba', 'scope' => 'read', 'expires_in_days' => 180])->assertSessionHasNoErrors();
        $this->actingAs($this->jhon)->post(route('profile.mcp.tokens.store'), ['name' => 'Claude · casa', 'scope' => 'read_write', 'expires_in_days' => 90])->assertSessionHasNoErrors();
        $this->assertSame(5, McpToken::query()->count());
    }

    public function test_destroy_revokes_an_own_token_and_keeps_its_log(): void
    {
        ['token' => $token, 'value' => $value] = $this->personalToken($this->jhon);
        $call = $this->logCall($token);

        $response = $this->actingAs($this->jhon)->delete(route('profile.mcp.tokens.destroy', $token));
        $response->assertRedirect(route('profile.mcp'));
        $this->assertNoStore($response);

        $token->refresh();
        $this->assertNotNull($token->revoked_at);
        $this->assertSame($this->jhon->id, $token->revoked_by);
        $this->assertTrue($call->exists && McpCall::query()->whereKey($call->id)->exists());
        $this->assertSame(1, Activity::query()->where('event', 'mcp_personal_token_revoked')->count());

        // Revoking again changes nothing; the token no longer opens the server.
        $revokedAt = $token->revoked_at->toIso8601String();
        $this->actingAs($this->jhon)->delete(route('profile.mcp.tokens.destroy', $token))->assertRedirect(route('profile.mcp'));
        $this->assertSame($revokedAt, $token->fresh()->revoked_at->toIso8601String());
        $this->initialize($value)->assertUnauthorized();
    }

    public function test_tokens_of_other_people_are_out_of_reach(): void
    {
        $leo = $this->user('Líder IA', 'Leonardo Herrera');
        ['token' => $other] = $this->personalToken($leo);

        $this->actingAs($this->jhon)->delete(route('profile.mcp.tokens.destroy', $other))->assertNotFound();
        $this->assertNull($other->fresh()->revoked_at);
        $this->actingAs($this->jhon)->get(route('profile.mcp', ['token' => $other->id]))->assertNotFound();
        $this->actingAs($this->jhon)->get(route('profile.mcp', ['token' => 'nope']))->assertNotFound();
        $this->actingAs($this->jhon)->get(route('profile.mcp'))->assertInertia(fn (Assert $page) => $page
            ->where('tokens', [])
            ->where('mcp_holders', ['Jhon Castro', 'Leonardo Herrera']));
    }

    public function test_without_permission_the_page_shows_who_has_it_and_routes_answer_403(): void
    {
        ['token' => $jhonToken] = $this->personalToken($this->jhon);
        $this->logCall($jhonToken);

        $this->actingAs($this->paola)->get(route('profile.mcp', ['token' => 'all']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Profile/Mcp')
                ->where('tokens', [])
                ->where('activity', null)
                ->where('selected_token', null)
                ->where('connection_test', null)
                ->where('created_token', null)
                ->where('mcp_holders', ['Jhon Castro'])
                ->where('server.url', url('/mcp')));

        $this->actingAs($this->paola)->post(route('profile.mcp.tokens.store'), ['name' => 'Mío', 'scope' => 'read', 'expires_in_days' => 30])->assertForbidden();
        $this->actingAs($this->paola)->delete(route('profile.mcp.tokens.destroy', $jhonToken))->assertForbidden();
        $this->actingAs($this->paola)->post(route('profile.mcp.test'))->assertForbidden();
        $this->assertSame(1, McpToken::query()->count());
    }

    public function test_activity_of_one_token_is_a_partial_reload(): void
    {
        ['token' => $laptop] = $this->personalToken($this->jhon, McpToken::SCOPE_READ_WRITE, 90, 'Claude · portátil');
        ['token' => $office] = $this->personalToken($this->jhon, McpToken::SCOPE_READ, 90, 'Claude Code · oficina');
        ['token' => $paolaToken] = $this->personalToken($this->paola);

        $this->travelTo(now()->subMinutes(10));
        $read = $this->logCall($laptop, ['tool' => 'buscar_solicitudes', 'target' => '“cartera”', 'message' => '4 resultados']);
        $this->travelBack();
        $note = $this->logCall($laptop, ['tool' => 'agregar_nota', 'target' => 'P-7 · T4']);
        $rejected = $this->logCall($office, ['tool' => 'actualizar_tarea', 'target' => 'P-7 · T4', 'result' => McpCall::RESULT_REJECTED, 'message' => 'token de solo lectura']);
        $this->travelTo(now()->subDays(8));
        $this->logCall($office, ['tool' => 'crear_tarea', 'target' => 'P-7', 'result' => McpCall::RESULT_REJECTED, 'message' => 'token de solo lectura']);
        $this->travelBack();
        $this->logCall($laptop, ['tool' => 'quien_soy', 'target' => null]);
        $this->logCall($paolaToken, ['user_id' => $this->paola->id]);
        McpCall::query()->create(['user_id' => $this->jhon->id, 'oauth_client_id' => 'abc', 'tool' => 'ver_solicitud', 'target' => 'GIA-1', 'result' => McpCall::RESULT_OK]);

        $props = $this->partial($this->jhon, route('profile.mcp', ['token' => $laptop->id]), 'activity,selected_token')
            ->assertOk()
            ->assertJsonMissingPath('props.tokens')
            ->json('props');

        $this->assertSame($laptop->id, $props['selected_token']['id']);
        $this->assertSame(self::TOKEN_KEYS, array_keys($props['selected_token']));
        $this->assertSame($laptop->id, $props['activity']['token_id']);
        $this->assertSame('180 días', $props['activity']['retention']);
        $this->assertSame([$note->id, $read->id], array_column($props['activity']['calls'], 'id'));
        $this->assertSame(self::CALL_KEYS, array_keys($props['activity']['calls'][0]));
        $this->assertSame([
            'id' => $read->id,
            'tool' => 'buscar_solicitudes',
            'summary' => '“cartera” · 4 resultados',
            'at' => $read->created_at->toIso8601String(),
            'token' => ['id' => $laptop->id, 'name' => 'Claude · portátil'],
            'result' => 'ok',
            'reason' => null,
        ], $props['activity']['calls'][1]);
        $this->assertSame([[
            'id' => $rejected->id,
            'tool' => 'actualizar_tarea',
            'summary' => 'P-7 · T4',
            'at' => $rejected->created_at->toIso8601String(),
            'token' => ['id' => $office->id, 'name' => 'Claude Code · oficina'],
            'result' => 'rejected',
            'reason' => 'token de solo lectura',
        ]], $props['activity']['rejected_elsewhere']);

        $all = $this->partial($this->jhon, route('profile.mcp', ['token' => 'all']), 'activity,selected_token')->json('props');
        $this->assertNull($all['selected_token']);
        $this->assertNull($all['activity']['token_id']);
        $this->assertSame([], $all['activity']['rejected_elsewhere']);
        $this->assertCount(4, $all['activity']['calls']);
    }

    public function test_unauthorized_calls_show_as_rejected(): void
    {
        ['token' => $token] = $this->personalToken($this->jhon);
        $this->logCall($token, ['result' => McpCall::RESULT_UNAUTHORIZED, 'message' => 'token vencido']);

        $props = $this->partial($this->jhon, route('profile.mcp', ['token' => $token->id]), 'activity,selected_token')->json('props');

        $this->assertSame(['rejected', 'token vencido'], [$props['activity']['calls'][0]['result'], $props['activity']['calls'][0]['reason']]);
    }

    public function test_connection_test_reports_a_reachable_server(): void
    {
        Http::fake([url('/mcp') => Http::response(['message' => 'Unauthenticated.'], 401, ['WWW-Authenticate' => 'Bearer realm="mcp"'])]);

        $response = $this->actingAs($this->jhon)->post(route('profile.mcp.test'));
        $response->assertRedirect(route('profile.mcp'));
        $this->assertNoStore($response);

        Http::assertSent(fn (HttpRequest $request) => $request->url() === url('/mcp')
            && $request->method() === 'POST'
            && ! $request->hasHeader('Authorization')
            && $request['method'] === 'initialize');

        $props = $this->partial($this->jhon, route('profile.mcp'), 'connection_test,server')->assertOk()->json('props');
        $this->assertSame(['status', 'ms', 'at'], array_keys($props['connection_test']));
        $this->assertSame('ok', $props['connection_test']['status']);
        $this->assertIsInt($props['connection_test']['ms']);
        $this->assertSame(['url' => url('/mcp'), 'active' => true], $props['server']);
        $this->assertSame(0, McpToken::query()->count());
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function failures(): array
    {
        return [
            'not found' => [404, 'mcp_not_found'],
            'server error' => [500, 'mcp_http_500'],
            'open endpoint' => [200, 'mcp_http_200'],
            'timeout' => ['timeout', 'mcp_timeout'],
            'tls' => ['tls', 'mcp_tls'],
        ];
    }

    #[DataProvider('failures')]
    public function test_connection_test_reports_failures(mixed $outcome, string $code): void
    {
        Http::fake([url('/mcp') => match ($outcome) {
            'timeout' => Http::failedConnection('cURL error 28: Operation timed out after 10001 milliseconds'),
            'tls' => Http::failedConnection('cURL error 60: SSL certificate problem: unable to get local issuer certificate'),
            default => Http::response('{}', $outcome),
        }]);

        $this->actingAs($this->jhon)->post(route('profile.mcp.test'))->assertRedirect(route('profile.mcp'));

        $this->actingAs($this->jhon)->get(route('profile.mcp'))->assertInertia(fn (Assert $page) => $page
            ->where('connection_test.status', 'failed')
            ->where('connection_test.code', $code)
            ->has('connection_test.at'));
    }

    public function test_inactive_server_is_reported(): void
    {
        config(['gestor.mcp.enabled' => false]);

        $this->actingAs($this->jhon)->get(route('profile.mcp'))->assertInertia(fn (Assert $page) => $page->where('server.active', false));
    }
}
