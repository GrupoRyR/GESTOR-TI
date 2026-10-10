<?php

namespace App\Console\Commands;

use App\Domain\Ai\ProjectReports;
use App\Models\AiProjectReport;
use Illuminate\Console\Command;

/**
 * Scheduled project reports (PROMPT 6.4.3): every weekly report whose
 * `next_send_at` arrived is sent to its saved recipients, then moved to
 * the next week. Runs every 15 minutes, so a report leaves within 15
 * minutes of its time.
 */
class ProjectReportsCommand extends Command
{
    protected $signature = 'ai:project-reports';

    protected $description = 'Envía los informes de proyecto programados';

    public function handle(ProjectReports $reports): int
    {
        $sent = 0;

        AiProjectReport::query()
            ->where('periodicity', 'weekly')
            ->whereNotNull('next_send_at')
            ->where('next_send_at', '<=', now())
            ->with('project.company')
            ->chunkById(50, function ($rows) use ($reports, &$sent): void {
                foreach ($rows as $report) {
                    /** @var AiProjectReport $report */
                    if ($report->project !== null) {
                        $sent += $reports->send($report->project, $report);
                    }

                    $report->forceFill(['next_send_at' => ProjectReports::nextSend($report->weekday, $report->time)])->save();
                }
            });

        $this->info("Informes enviados: {$sent}.");

        return self::SUCCESS;
    }
}
