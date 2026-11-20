<?php

namespace App\Scheduler;

use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Recurring jobs: catch-up HelloAsso fetch (safety net for missed webhooks) and
 * the old-payment purge, both previously configured via Spring's @Scheduled cron
 * properties in application.properties.
 */
#[AsSchedule('default')]
class AppSchedule implements ScheduleProviderInterface
{
    public function __construct(
        private readonly CacheInterface $scheduleCache,
    ) {
    }

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->with(
                RecurringMessage::cron('*/10 * * * *', new RunCommandMessage('app:helloasso:fetch')),
                RecurringMessage::cron('0 */6 * * *', new RunCommandMessage('app:payments:purge')),
                RecurringMessage::cron('*/15 * * * *', new RunCommandMessage('app:backup:auto')),
                RecurringMessage::cron('30 3 * * *', new RunCommandMessage('app:activity-log:purge')),
            )
            ->stateful($this->scheduleCache)
            // After a worker outage, run each missed job once to catch up rather
            // than firing it once per tick that was skipped (the fetch is a
            // safety net and the purges are idempotent — a backlog burst is
            // pointless).
            ->processOnlyLastMissedRun(true);
    }
}
