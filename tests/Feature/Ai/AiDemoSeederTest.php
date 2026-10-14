<?php

namespace Tests\Feature\Ai;

use App\Models\AiProject;
use App\Models\AiRequest;
use App\Models\User;
use App\Support\Ai\Presenters\RequestPresenter;
use Database\Seeders\AiDemoSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Notification;

class AiDemoSeederTest extends AiTestCase
{
    public function test_it_seeds_the_design_examples_idempotently(): void
    {
        Notification::fake();

        $this->seed(AiDemoSeeder::class);
        $this->seed(AiDemoSeeder::class);

        $gia148 = AiRequest::query()->where('key', 'GIA-148')->firstOrFail();
        $this->assertSame(3, $gia148->votes_count);
        $this->assertSame(9, AiRequest::query()->where('key', 'like', 'GIA-1%')->count());
        // GIA-139 has its demo project (B5), created once.
        $this->assertSame(1, AiProject::query()->count());
        $this->assertSame(7, AiProject::query()->sole()->tasks()->count());
        $this->assertSame(1, AiProject::query()->sole()->tasks()->whereHas('blockingRequirements')->count());
        // 3 h × 4 people, M, high: 12 / 2 × 1.2 = 7.2 → 42.
        $this->assertSame(42, AiRequest::query()->where('key', 'GIA-146')->firstOrFail()->priority_score);
        $this->assertSame('GIA-154', $this->request()->key);
    }

    public function test_edward_carries_his_confirmed_job_title(): void
    {
        Notification::fake();

        $this->seed(AiDemoSeeder::class);

        $edward = User::query()->where('email', 'edward@demo.local')->firstOrFail();
        $this->assertSame('CEO', $edward->employee?->job_title);
        $this->assertSame('CEO', RequestPresenter::person($edward)['job_title']);
    }

    public function test_it_is_not_part_of_the_database_seeder(): void
    {
        $this->assertStringNotContainsString('AiDemoSeeder', (string) file_get_contents((new \ReflectionClass(DatabaseSeeder::class))->getFileName()));
    }
}
