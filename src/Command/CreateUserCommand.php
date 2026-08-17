<?php

namespace App\Command;

use App\Entity\Client;
use App\Entity\User;
use App\Repository\ClientRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:user:create',
    description: 'Creates a Cyllos user (global admin, or scoped to a client).',
)]
class CreateUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly ClientRepository $clientRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'User email (login)')
            ->addArgument('password', InputArgument::REQUIRED, 'Plain text password')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Grant ROLE_ADMIN (global Cylaos admin, sees all clients)')
            ->addOption('developer', null, InputOption::VALUE_NONE, 'Grant ROLE_DEVELOPER (implies admin access, plus the audit log)')
            ->addOption('ceo', null, InputOption::VALUE_NONE, 'Grant ROLE_CEO (implies admin and developer access, plus team management)')
            ->addOption('client', null, InputOption::VALUE_REQUIRED, 'Client slug this user belongs to (grants ROLE_CLIENT)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = $input->getArgument('email');
        $password = $input->getArgument('password');
        $isAdmin = (bool) $input->getOption('admin');
        $isDeveloper = (bool) $input->getOption('developer');
        $isCeo = (bool) $input->getOption('ceo');
        $clientSlug = $input->getOption('client');

        if ($this->userRepository->findOneBy(['email' => $email]) !== null) {
            $io->error(sprintf('A user with email "%s" already exists.', $email));

            return Command::FAILURE;
        }

        if (!$isAdmin && !$isDeveloper && !$isCeo && $clientSlug === null) {
            $io->error('Provide --admin, --developer, --ceo, or --client=<slug>.');

            return Command::FAILURE;
        }

        $client = null;
        if ($clientSlug !== null) {
            $client = $this->clientRepository->findOneBySlug($clientSlug);
            if ($client === null) {
                $io->error(sprintf('No client found with slug "%s".', $clientSlug));

                return Command::FAILURE;
            }
        }

        $roles = [];
        if ($isAdmin) {
            $roles[] = User::ROLE_ADMIN;
        }
        if ($isDeveloper) {
            $roles[] = User::ROLE_DEVELOPER;
        }
        if ($isCeo) {
            $roles[] = User::ROLE_CEO;
        }
        if ($client instanceof Client) {
            $roles[] = User::ROLE_CLIENT;
        }

        $user = new User();
        $user->setEmail($email);
        $user->setRoles($roles);
        $user->setClient($client);
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf('User "%s" created with roles [%s]%s.', $email, implode(', ', $roles), $client instanceof Client ? ' for client ' . $client->getName() : ''));

        return Command::SUCCESS;
    }
}
