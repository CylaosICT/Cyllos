<?php

namespace App\Command;

use App\Repository\PaymentRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Purges old payment records, ported from PurgeDatabaseService.java. Only
 * payments successfully credited in Cyclos are eligible: anything still
 * pending or in error stays forever, so a client dispute always has a record
 * to point to (see PaymentRepository::findPurgeableIdsByInsertionDateBefore).
 */
#[AsCommand(
    name: 'app:payments:purge',
    description: 'Deletes payments older than the configured retention period.',
)]
class PurgePaymentsCommand extends Command
{
    public function __construct(
        private readonly PaymentRepository $paymentRepository,
        private readonly int $defaultRetentionDays,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('retention-days', null, InputOption::VALUE_REQUIRED, 'Number of days to retain payments', (string) $this->defaultRetentionDays);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $retentionDays = (int) $input->getOption('retention-days');

        $threshold = (new \DateTimeImmutable())->modify(sprintf('-%d days', $retentionDays));
        $ids = $this->paymentRepository->findPurgeableIdsByInsertionDateBefore($threshold);

        $this->paymentRepository->deleteByIds($ids);

        $io->success(sprintf('%d paiement(s) crédité(s) supprimé(s) (rétention : %d jours). Les paiements non traités ou en échec ne sont jamais purgés.', \count($ids), $retentionDays));

        return Command::SUCCESS;
    }
}
