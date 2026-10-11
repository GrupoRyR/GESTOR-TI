<?php

namespace Tests\Feature\Assistant\Chat;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Assistant\AssistantTestCase;

/**
 * Asistente TI chat (types/pages/assistant-chat.ts). The AI provider is
 * ALWAYS faked: DeepSeek's OpenAI-compatible endpoint answers from a
 * sequence (`provider()`), stray HTTP requests fail the test.
 * `$this->superAdmin` (Leonardo) holds the credential permissions; Jhon
 * (Líder IA) has `assistant.use` but not `assistant.credentials`.
 */
abstract class ChatTestCase extends AssistantTestCase
{
    protected const ENDPOINT = 'api.deepseek.com/*';

    protected const SHARED = ['auth', 'companies', 'current_company_id', 'flash', 'notifications_unread', 'modules', 'errors'];

    protected const PAGE_PROPS = [
        'status', 'monthly_cap', 'conversations', 'conversation', 'selected_source', 'revealed_credential',
        'first_open_suggestions', 'unanswered_count', 'drafts_count', 'can_configure_ai', 'access_holders', 'now',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.deepseek.api_key' => 'test-deepseek-key']);
        Http::preventStrayRequests();
        Notification::fake();
    }

    /**
     * Fakes the provider with the given chat completion bodies (arrays) or
     * HTTP statuses (ints), in order.
     *
     * @param  array<string, mixed>|int  ...$responses
     */
    protected function provider(array|int ...$responses): void
    {
        $sequence = Http::sequence();

        foreach ($responses as $response) {
            is_int($response) ? $sequence->push(['error' => ['message' => 'fallo', 'type' => 'server_error']], $response) : $sequence->push($response);
        }

        Http::fake([self::ENDPOINT => $sequence]);
    }

    /**
     * A final answer of the model.
     *
     * @return array<string, mixed>
     */
    protected function text(string $content, int $prompt = 1200, int $cached = 1000, int $output = 80): array
    {
        return $this->completion(['role' => 'assistant', 'content' => $content], 'stop', $prompt, $cached, $output);
    }

    /**
     * The model asks to run a tool.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function tool(string $name, array $arguments, string $id = 'call_1', int $prompt = 1000, int $cached = 900, int $output = 20): array
    {
        return $this->completion([
            'role' => 'assistant',
            'content' => '',
            'tool_calls' => [[
                'id' => $id,
                'type' => 'function',
                'function' => ['name' => $name, 'arguments' => json_encode($arguments)],
            ]],
        ], 'tool_calls', $prompt, $cached, $output);
    }

    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>
     */
    private function completion(array $message, string $finish, int $prompt, int $cached, int $output): array
    {
        return [
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion',
            'model' => 'deepseek-chat',
            'choices' => [['index' => 0, 'message' => $message, 'finish_reason' => $finish]],
            'usage' => [
                'prompt_tokens' => $prompt,
                'completion_tokens' => $output,
                'total_tokens' => $prompt + $output,
                'prompt_cache_hit_tokens' => $cached,
                'prompt_cache_miss_tokens' => $prompt - $cached,
            ],
        ];
    }

    /**
     * Bodies of every request sent to the provider, in order.
     *
     * @return list<array<string, mixed>>
     */
    protected function sentBodies(): array
    {
        return Http::recorded()
            ->map(fn (array $pair) => json_decode($pair[0]->body(), true))
            ->values()
            ->all();
    }

    protected function sentRaw(): string
    {
        return Http::recorded()->map(fn (array $pair) => $pair[0]->body())->implode("\n");
    }

    protected function assertProviderCalls(int $count): void
    {
        Http::assertSentCount($count);
        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'api.deepseek.com')
            && $request->hasHeader('Authorization', 'Bearer test-deepseek-key'));
    }

    /**
     * Asks through the chat endpoint (as a normal Inertia POST would).
     */
    protected function ask(User $user, string $question, ?AssistantConversation $conversation = null): TestResponse
    {
        $params = $conversation === null ? [] : ['conversation' => $conversation->id];

        return $this->actingAs($user)->post(route('assistant.messages.store', $params), ['question' => $question]);
    }

    protected function conversationOf(User $user): AssistantConversation
    {
        return AssistantConversation::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
    }

    /**
     * @return list<AssistantMessage>
     */
    protected function messagesOf(AssistantConversation $conversation): array
    {
        return $conversation->messages()->orderBy('id')->get()->all();
    }

    protected function setting(string $key, mixed $value): void
    {
        app(Settings::class)->set($key, $value);
    }

    /**
     * Inertia partial reload headers for `Assistant/Chat`.
     *
     * @return array<string, string>
     */
    protected function partial(string $props): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => 'Assistant/Chat',
            'X-Inertia-Partial-Data' => $props,
        ];
    }

    /**
     * @return list<string>
     */
    protected function propKeys(string $url, ?User $as = null): array
    {
        $keys = array_keys($this->actingAs($as ?? $this->jhon)->get($url)->assertOk()->viewData('page')['props']);
        sort($keys);

        return $keys;
    }

    /**
     * @return list<string>
     */
    protected function withShared(array $keys): array
    {
        $all = [...self::SHARED, ...$keys];
        sort($all);

        return $all;
    }
}
