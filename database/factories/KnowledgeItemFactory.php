<?php

namespace Database\Factories;

use App\Models\KnowledgeItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A report card reviewed 6 months from now. The key comes from the
 * sequence; companies are a pivot: attach them in the test.
 *
 * @extends Factory<KnowledgeItem>
 */
class KnowledgeItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => 'report',
            'title' => 'Informe '.fake()->unique()->words(2, true),
            'description' => null,
            'fields' => [
                'shows' => 'Los pedidos del día anterior',
                'source_system' => null,
                'recipients' => null,
                'schedule' => null,
                'format' => null,
            ],
            'visibility' => 'company',
            'review_at' => now()->addMonths(6)->toDateString(),
            'source' => 'manual',
            'is_current' => true,
        ];
    }
}
