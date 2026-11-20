<?php

namespace App\Command;

use App\Backup\BackupSettings;
use App\Backup\DatabaseBackupManager;
use App\Entity\DatabaseBackup;
use App\Repository\DatabaseBackupRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Takes the scheduled database backup when one is due, then prunes the old
 * automatic ones. Woken every 15 minutes by AppSchedule; does nothing when
 * automatic backups are disabled or the last one is recent enough.
 */
#[AsCommand(name: 'app:backup:auto', description: 'Takes the automatic database backup if one is due, and prunes old ones.')]
class BackupAutoCommand extends Command
{
    public function __construct(
        private readonly BackupSettings $settings,
        private readonly DatabaseBackupManager $manager,
        private readonly DatabaseBackupRepository $backups,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Back up now, even if disabled or not due');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->getOption('force')) {
            if (!$this->settings->isEnabled()) {
                return Command::SUCCESS;
            }

            $last = $this->backups->findLatestOfType(DatabaseBackup::TYPE_AUTO);
            // 5 minutes of slack so a run slightly early in the cycle isn't skipped.
            $due = $last === null || $last->getCreatedAt() <= new \DateTimeImmutable(\sprintf('-%d minutes', $this->settings->intervalHours() * 60 - 5));
            if (!$due) {
                return Command::SUCCESS;
            }
        }

        $backup = $this->manager->create(DatabaseBackup::TYPE_AUTO, 'automatique');
        $pruned = $this->manager->pruneAutomatic($this->settings->retention());
        $output->writeln(\sprintf('Sauvegarde %s créée (%d ancienne(s) supprimée(s)).', $backup->getFilename(), $pruned));

        return Command::SUCCESS;
    }
}
