<?php

namespace App\Jobs;

use App\Domain\Suggestions\SuggestionGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Generates one batch of AI suggestions on the database queue (shared
 * hosting: the cron worker of routes/console.php runs it within a minute;
 * the page polls `only: ['suggestions']` meanwhile). One try: the AI SDK
 * already retries a transient failure once (RetryTransientStep) and every
 * call costs money; a failure becomes an `error` suggestion with "Volver a
 * intentar". See App\Domain\Suggestions\SuggestionGenerator.
 */
class GenerateSuggestions implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** AiRuntime's 60 s provider timeout plus one SDK retry. */
    public int $timeout = 150;

    /**
     * @param  list<int>  $ids
     */
    public function __construct(
        public readonly array $ids,
        public readonly bool $withDuplicates = false,
    ) {}

    public function handle(SuggestionGenerator $generator): void
    {
        $generator->generate($this->ids, $this->withDuplicates);
    }
}
