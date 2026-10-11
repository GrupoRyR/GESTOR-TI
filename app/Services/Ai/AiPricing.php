<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Log;

/**
 * Cost of one provider call in USD from the configured prices
 * (config/gestor.php `ai.prices`, USD per million tokens): uncached input,
 * cached input (DeepSeek "cache hit") and output.
 */
final class AiPricing
{
    public function cost(string $model, int $inputTokens, int $cachedTokens, int $outputTokens): float
    {
        $prices = config('gestor.ai.prices.'.$model);

        if (! is_array($prices)) {
            Log::warning('AI model without configured prices; cost recorded as 0', ['model' => $model]);

            return 0.0;
        }

        $cached = min(max(0, $cachedTokens), max(0, $inputTokens));
        $uncached = max(0, $inputTokens) - $cached;

        return round((
            $uncached * (float) ($prices['input'] ?? 0)
            + $cached * (float) ($prices['cached_input'] ?? 0)
            + max(0, $outputTokens) * (float) ($prices['output'] ?? 0)
        ) / 1_000_000, 6);
    }
}
