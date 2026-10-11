<?php

namespace Database\Factories;

use App\Models\GitHubRepository;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GitHubRepository>
 */
class GitHubRepositoryFactory extends Factory
{
    protected $model = GitHubRepository::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->slug(2);

        return [
            'github_id' => fake()->unique()->numberBetween(1000, 9_999_999),
            'full_name' => "GrupoRyR/{$name}",
            'name' => $name,
            'default_branch' => 'main',
            'private' => true,
            'html_url' => "https://github.com/GrupoRyR/{$name}",
            'language' => 'PHP',
            'pushed_at' => now()->subDay(),
            'branches' => ['main'],
            'installation_id' => 42,
            'last_synced_at' => now(),
        ];
    }
}
