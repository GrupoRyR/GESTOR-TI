<?php

namespace Tests\Feature\Ai\Suggestions;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Http\Client\ResponseSequence;
use Illuminate\Support\Facades\Http;

/**
 * Fakes the AI provider at its HTTP endpoint (DeepSeek chat completions),
 * as the Stage C tests do, and reads what was sent to it.
 */
trait FakesAiProvider
{
    protected string $endpoint = 'api.deepseek.com/*';

    /** One sequence per test: later calls queue more answers (Http fakes match the first stub registered). */
    private ?ResponseSequence $answers = null;

    private bool $faked = false;

    /**
     * Fakes the provider with the given answers (arrays are JSON content of
     * the model, strings raw content, ints HTTP error statuses), in order,
     * after the answers queued earlier in the test.
     *
     * @param  array<string, mixed>|string|int  ...$answers
     */
    protected function provider(array|string|int ...$answers): void
    {
        $sequence = $this->answers ??= Http::sequence();

        foreach ($answers as $answer) {
            if (is_int($answer)) {
                $sequence->push(['error' => ['message' => 'fallo', 'type' => 'server_error']], $answer);

                continue;
            }

            $content = is_array($answer) ? (string) json_encode($answer, JSON_UNESCAPED_UNICODE) : $answer;
            $sequence->push([
                'id' => 'chatcmpl-test',
                'object' => 'chat.completion',
                'created' => 1,
                'model' => 'deepseek-chat',
                'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 120, 'total_tokens' => 1020, 'prompt_cache_hit_tokens' => 0, 'prompt_cache_miss_tokens' => 900],
            ]);
        }

        if (! $this->faked) {
            $this->faked = true;
            Http::fake([$this->endpoint => $sequence]);
        }
    }

    /**
     * Raw bodies sent to the provider.
     */
    protected function sentRaw(): string
    {
        return Http::recorded()->map(fn (array $pair) => $pair[0]->body())->implode("\n");
    }

    /**
     * The user prompt of the last provider call.
     */
    protected function lastPrompt(): string
    {
        $body = json_decode((string) Http::recorded()->last()[0]->body(), true);

        return (string) collect($body['messages'])->firstWhere('role', 'user')['content'];
    }

    /**
     * Inertia partial reload headers for `only: [...]`.
     *
     * @return array<string, string>
     */
    protected function partial(string $component, string $props): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => $component,
            'X-Inertia-Partial-Data' => $props,
        ];
    }
}
