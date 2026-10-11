<?php

namespace App\Console\Commands;

use App\Models\KnowledgeItem;
use App\Models\User;
use App\Notifications\KnowledgeReviewReminder;
use Illuminate\Console\Command;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

/**
 * Knowledge review reminder (PROMPT 6.8.1), weekly: each active holder of
 * `assistant.knowledge.manage` gets one `KnowledgeReviewReminder` with the
 * cards they can see (KnowledgeItem::visibleTo) whose review date has
 * passed (`review_at`, 6 months after the last review). Nothing is sent
 * to someone with no overdue card.
 */
class KnowledgeReviewRemindersCommand extends Command
{
    protected $signature = 'assistant:knowledge-review-reminders';

    protected $description = 'Avisa a quienes administran la base de conocimiento de las fichas con la revisión vencida';

    public function handle(): int
    {
        try {
            $managers = User::permission(KnowledgeItem::MANAGE_PERMISSION)->where('active', true)->orderBy('id')->get();
        } catch (PermissionDoesNotExist) {
            $managers = collect();
        }

        $today = now()->toDateString();
        $sent = 0;

        foreach ($managers as $manager) {
            $overdue = KnowledgeItem::query()->visibleTo($manager)
                ->whereDate('review_at', '<', $today)
                ->orderBy('review_at')->orderBy('id');

            $count = (clone $overdue)->count();
            if ($count === 0) {
                continue;
            }

            $titles = $overdue->limit(KnowledgeReviewReminder::LISTED)->get(['key', 'title'])
                ->map(fn (KnowledgeItem $item) => "{$item->key} · {$item->title}")
                ->values()->all();

            $manager->notify(new KnowledgeReviewReminder($count, $titles));
            $sent++;
        }

        $this->info("Recordatorios enviados: {$sent}.");

        return self::SUCCESS;
    }
}
