<?php

namespace App\Command;

use App\Payment\PaymentProcessor;
use App\Repository\ClientRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Catch-up fetch of recent HelloAsso payments, for every active client (or a
 * single one via --client). Equivalent to the manual "Synchro Hello Asso"
 * button in the legacy tool, run here as a safety net for missed webhooks.
 */
#[AsCommand(
    name: 'app:helloasso:fetch',
    description: 'Fetches recent HelloAsso payments for active clients, to catch up on any missed webhook notification.',
)]
class FetchHelloAssoPaymentsCommand extends Command
{
    public function __construct(
        private readonly ClientRepository $clientRepository,
        private readonly PaymentProcessor $paymentProcessor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('client', null, InputOption::VALUE_REQUIRED, 'Only fetch for this client slug');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $clientSlug = $input->getOption('client');
        if ($clientSlug !== null) {
            $client = $this->clientRepository->findOneBySlug($clientSlug);
            if ($client === null) {
                $io->error(sprintf('No client found with slug "%s".', $clientSlug));

                return Command::FAILURE;
            }
            $clients = [$client];
        } else {
            $clients = $this->clientRepository->findAllActive();
        }

        foreach ($clients as $client) {
            $added = $this->paymentProcessor->fetchMissingPayments($client);
            $io->writeln(sprintf('%s: %d nouveau(x) paiement(s)', $client->getName(), $added));
        }

        return Command::SUCCESS;
    }
}
