<?php

namespace App\Tests\Functional;

use App\Entity\ActivityLog;
use App\Entity\Client;
use App\Entity\User;
use App\Repository\ActivityLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The Doctrine audit listener records create/update/delete of Client, Payment
 * and User — and never writes secret/password values into the log.
 */
class ActivityLogSubscriberTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ActivityLogRepository $logs;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->logs = self::getContainer()->get(ActivityLogRepository::class);

        $this->entityManager->createQuery('DELETE FROM App\Entity\ActivityLog')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\EmailAlias')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Payment')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\ClientSetting')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\CyclosConfig')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\CheckoutOrder')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\StripeConfig')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\HelloAssoConfig')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Client')->execute();
    }

    /** @return list<string> */
    private function actions(): array
    {
        return array_map(static fn (ActivityLog $l) => $l->getAction(), $this->logs->findRecent(50));
    }

    public function testCreatingAClientIsAudited(): void
    {
        $client = (new Client())->setName('Audited')->setSlug('audited')->setActive(true);
        $this->entityManager->persist($client);
        $this->entityManager->flush();

        self::assertContains('client.created', $this->actions());
    }

    public function testUpdatingAUserRecordsChangedFieldsButNeverThePassword(): void
    {
        $user = (new User())->setEmail('u@e.test')->setPassword('old-hash')->setRoles(['ROLE_CLIENT']);
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $this->entityManager->createQuery('DELETE FROM App\Entity\ActivityLog')->execute();

        $user->setEmail('new@e.test');
        $user->setPassword('new-hash');
        $this->entityManager->flush();

        $updateLog = null;
        foreach ($this->logs->findRecent(50) as $log) {
            if ($log->getAction() === 'user.updated') {
                $updateLog = $log;
            }
        }

        self::assertNotNull($updateLog);
        $context = $updateLog->getContext() ?? '';
        self::assertStringContainsString('email', $context);
        self::assertStringNotContainsString('password', $context);
    }
}
