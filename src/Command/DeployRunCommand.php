<?php

namespace App\Command;

use App\Deployment\DeploymentRunner;
use App\Repository\UpdateRunRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Internal: executes an update recorded by the back-office (UpdateLauncher
 * starts it as a detached process). Not meant to be run by hand.
 */
#[AsCommand(name: 'app:deploy:run', description: 'Runs the deployment of a recorded update (internal).')]
class DeployRunCommand extends Command
{
    public function __construct(
        private readonly UpdateRunRepository $runs,
        private readonly DeploymentRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('run', InputArgument::REQUIRED, 'Update id');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $run = $this->runs->find((int) $input->getArgument('run'));
        if ($run === null || !$run->isRunning()) {
            $output->writeln('<error>Mise à jour introuvable ou déjà terminée.</error>');

            return Command::FAILURE;
        }

        $this->runner->execute($run);

        return Command::SUCCESS;
    }
}
