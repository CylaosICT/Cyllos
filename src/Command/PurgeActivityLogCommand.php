<?php

namespace App\Command;

use App\Repository\ActivityLogRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Bounds the activity_log table, which the /dev journal can still be wiped from
 * by hand. Two retention knobs exist — outbound API call traces (action "api.*",
 * by far the most rows — one per HelloAsso/Cyclos call, several per catch-up
 * cycle) versus audit lines (entity changes, sign-ins) — but both default to the
 * same one-month window. Scheduled daily by AppSchedule.
 */
#[AsCommand(
    name: 'app:activity-log:purge',
    description: 'Deletes activity_log rows older than their retention (API call traces and audit lines, independently configurable).',
)]
class PurgeActivityLogCommand extends Command
{
    public function __construct(
        private readonly ActivityLogRepository $activityLogRepository,
        private readonly int $apiRetentionDays,
        private readonly int $retentionDays,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('api-retention-days', null, InputOption::VALUE_REQUIRED, 'Days to retain outbound API call traces', (string) $this->apiRetentionDays);
        $this->addOption('retention-days', null, InputOption::VALUE_REQUIRED, 'Days to retain every other log row (audit lines)', (string) $this->retentionDays);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $apiDays = (int) $input->getOption('api-retention-days');
        $allDays = (int) $input->getOption('retention-days');

        $now = new \DateTimeImmutable();
        $apiDeleted = $this->activityLogRepository->deleteApiCallsOlderThan($now->modify(\sprintf('-%d days', $apiDays)));
        $allDeleted = $this->activityLogRepository->deleteOlderThan($now->modify(\sprintf('-%d days', $allDays)));

        $io->success(\sprintf(
            '%d trace(s) API supprimée(s) (> %d j) et %d ligne(s) d\'audit supprimée(s) (> %d j).',
            $apiDeleted,
            $apiDays,
            $allDeleted,
            $allDays,
        ));

        return Command::SUCCESS;
    }
}
