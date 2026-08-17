<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:generate-encryption-key',
    description: 'Generates a base64-encoded key to use as APP_ENCRYPTION_KEY for encrypting client secrets at rest.',
)]
class GenerateEncryptionKeyCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $key = base64_encode(random_bytes(32));

        $io->success('Generated encryption key. Add this to your .env.local (never commit it):');
        $io->writeln(sprintf('APP_ENCRYPTION_KEY=%s', $key));

        return Command::SUCCESS;
    }
}
