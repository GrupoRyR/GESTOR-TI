<?php

namespace Tests\Feature\Ai\Costs;

use App\Notifications\AiBudgetAlert;
use App\Notifications\AiHoursReminder;
use App\Notifications\AiProjectReapprovalRequested;
use App\Notifications\AiRequirementAnswered;
use App\Notifications\AiRequirementReminder;
use App\Notifications\AiRequirementRequested;
use App\Notifications\AiTaskUnblocked;
use App\Notifications\GestorNotification;
use ReflectionClass;

/**
 * Project notices have their own entries in config/notifications.php
 * (label + hint), so people choose channels per kind of notice.
 */
class ProjectNotificationEventsTest extends CostsTestCase
{
    public function test_project_events_are_in_the_catalog_and_used_by_their_notifications(): void
    {
        $expected = [
            AiRequirementRequested::class => 'ai.requirement.requested',
            AiRequirementReminder::class => 'ai.requirement.requested',
            AiRequirementAnswered::class => 'ai.requirement.answered',
            AiTaskUnblocked::class => 'ai.task.unblocked',
            AiBudgetAlert::class => 'ai.project.budget',
            AiProjectReapprovalRequested::class => 'ai.project.reapproval',
            AiHoursReminder::class => 'ai.hours.reminder',
        ];
        $events = config('notifications.events');

        foreach ($expected as $class => $event) {
            $this->assertArrayHasKey($event, $events);
            $this->assertNotEmpty($events[$event]['label']);
            $this->assertNotEmpty($events[$event]['hint']);
            $this->assertEmpty(array_diff($events[$event]['channels'], ['mail', 'telegram', 'database']));

            /** @var GestorNotification $instance */
            $instance = (new ReflectionClass($class))->newInstanceWithoutConstructor();
            $this->assertSame($event, $instance->eventType(), $class);
        }
    }
}
