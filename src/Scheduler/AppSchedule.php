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
                RecurringMessage::cron('* * * * *', new RunCommandMessage('app:helloasso:fetch')),# fetch toutes les minutes à la place de toutes les 15m : '*/15 * * * *'
                RecurringMessage::cron('0 3 * * *', new RunCommandMessage('app:payments:purge')),
            )
            ->stateful($this->scheduleCache);
    }
}
