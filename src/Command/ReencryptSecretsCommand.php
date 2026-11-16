<?php

namespace App\Command;

use App\Repository\CyclosConfigRepository;
use App\Repository\HelloAssoConfigRepository;
use App\Repository\StripeConfigRepository;
use App\Repository\UserRepository;
use App\Security\SecretEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rewrites every stored secret (HelloAsso client secret, Stripe keys, Cyclos password, user
 * TOTP secret) with the current primary APP_ENCRYPTION_KEY. Run this after a key
 * rotation — set the
 * new key as APP_ENCRYPTION_KEY, move the old one to APP_ENCRYPTION_KEYS_LEGACY,
 * reload, run this command, then drop APP_ENCRYPTION_KEYS_LEGACY.
 *
 * Rows already encrypted with the primary key are left untouched. A row that no
 * configured key can decrypt is reported and skipped (never wiped).
 */
#[AsCommand(
    name: 'app:secrets:reencrypt',
    description: 'Re-encrypts all stored secrets with the current primary encryption key (after a key rotation).',
)]
class ReencryptSecretsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SecretEncryptor $secretEncryptor,
        private readonly HelloAssoConfigRepository $helloAssoConfigRepository,
        private readonly CyclosConfigRepository $cyclosConfigRepository,
        private readonly StripeConfigRepository $stripeConfigRepository,
        private readonly UserRepository $userRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change without writing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $reencrypted = 0;
        $alreadyCurrent = 0;
        $failures = [];

        /** @var list<array{object, string, callable(): string, callable(string): void}> $targets */
        $targets = [];
        foreach ($this->helloAssoConfigRepository->findAll() as $config) {
            $targets[] = [$config, 'HelloAssoConfig#' . $config->getId() . ' (clientSecret)', $config->getClientSecretEncrypted(...), $config->setClientSecretEncrypted(...)];
        }
        foreach ($this->stripeConfigRepository->findAll() as $config) {
            $targets[] = [$config, 'StripeConfig#' . $config->getId() . ' (secretKey)', $config->getSecretKeyEncrypted(...), $config->setSecretKeyEncrypted(...)];
            $targets[] = [$config, 'StripeConfig#' . $config->getId() . ' (webhookSecret)', $config->getWebhookSecretEncrypted(...), $config->setWebhookSecretEncrypted(...)];
        }
        foreach ($this->cyclosConfigRepository->findAll() as $config) {
            $targets[] = [$config, 'CyclosConfig#' . $config->getId() . ' (password)', $config->getPasswordEncrypted(...), $config->setPasswordEncrypted(...)];
        }
        foreach ($this->userRepository->findAll() as $user) {
            if ($user->getTotpSecretEncrypted() === null) {
                continue;
            }
            $targets[] = [
                $user,
                'User#' . $user->getId() . ' (totpSecret)',
                static fn (): string => (string) $user->getTotpSecretEncrypted(),
                $user->setTotpSecretEncrypted(...),
            ];
        }

        foreach ($targets as [$entity, $label, $getter, $setter]) {
            $current = $getter();

            if ($this->secretEncryptor->isEncryptedWithPrimaryKey($current)) {
                ++$alreadyCurrent;

                continue;
            }

            try {
                $plain = $this->secretEncryptor->decrypt($current);
            } catch (\RuntimeException $e) {
                $failures[] = $label . ' — ' . $e->getMessage();

                continue;
            }

            if (!$dryRun) {
                $setter($this->secretEncryptor->encrypt($plain));
            }
            ++$reencrypted;
        }

        if (!$dryRun && $reencrypted > 0) {
            $this->entityManager->flush();
        }

        $io->writeln(\sprintf('%d secret(s) déjà sur la clé courante.', $alreadyCurrent));
        $io->writeln(\sprintf('%d secret(s) %s avec la clé courante.', $reencrypted, $dryRun ? 'à re-chiffrer' : 're-chiffré(s)'));

        if ($failures !== []) {
            $io->error(\sprintf('%d secret(s) indéchiffrable(s) avec les clés configurées :', \count($failures)));
            $io->listing($failures);

            return Command::FAILURE;
        }

        $io->success($dryRun ? 'Dry-run terminé.' : 'Re-chiffrement terminé.');

        return Command::SUCCESS;
    }
}
