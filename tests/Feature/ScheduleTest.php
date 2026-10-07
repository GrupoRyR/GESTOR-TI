<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Spatie\Backup\Config\Config as BackupConfig;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    /**
     * Scheduled event whose command contains the given artisan command.
     */
    private function event(string $command): ?Event
    {
        return collect(app(Schedule::class)->events())
            ->first(fn (Event $event) => str_contains((string) $event->command, $command));
    }

    public function test_the_queue_worker_runs_every_minute_and_exits_when_empty(): void
    {
        $event = $this->event('queue:work');

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertStringContainsString('--stop-when-empty', $event->command);
        $this->assertStringContainsString('--max-time=50', $event->command);
        $this->assertStringContainsString('--tries=3', $event->command);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_backups_are_cleaned_run_and_monitored_daily_in_bogota_time(): void
    {
        foreach (['backup:clean' => '30 1 * * *', 'backup:run' => '0 2 * * *', 'backup:monitor' => '0 8 * * *'] as $command => $expression) {
            $event = $this->event($command);

            $this->assertNotNull($event, $command.' is not scheduled');
            $this->assertSame($expression, $event->expression, $command);
            $this->assertSame('America/Bogota', (string) $event->timezone, $command);
        }
    }

    public function test_maintenance_commands_are_scheduled(): void
    {
        $this->assertStringContainsString('--hours=168', $this->event('queue:prune-failed')?->command ?? '');
        $this->assertNotNull($this->event('activitylog:clean'));
        $this->assertNotNull($this->event('gestor:sync-employees'));
    }

    public function test_the_backup_configuration_is_valid_and_private(): void
    {
        $config = BackupConfig::fromArray(config('backup'));

        $this->assertSame('gestor-ti', $config->backup->name);
        $this->assertSame(['mysql'], $config->backup->source->databases);
        $this->assertSame(['local'], $config->backup->destination->disks);
        $this->assertStringNotContainsString('public', (string) config('filesystems.disks.local.root'));
        $this->assertSame(7, config('backup.cleanup.default_strategy.keep_all_backups_for_days'));
        $this->assertSame(4, config('backup.cleanup.default_strategy.keep_weekly_backups_for_weeks'));
        $this->assertSame(3, config('backup.cleanup.default_strategy.keep_monthly_backups_for_months'));
        $this->assertSame(365, config('activitylog.delete_records_older_than_days'));
    }
}
