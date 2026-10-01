<?php

namespace App\Tests\Functional;

use App\Entity\ActivityLog;
use App\Repository\ActivityLogRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:activity-log:purge keeps the table bounded: everything (API call traces
 * and audit lines alike) expires past the retention, recent rows stay.
 */
class PurgeActivityLogCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ActivityLogRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(ActivityLogRepository::class);
        $this->entityManager->createQuery('DELETE FROM App\Entity\ActivityLog')->execute();
    }

    private function persistLogAged(string $action, string $ageModifier): int
    {
        $log = (new ActivityLog())->setAction($action)->setSummary('t');
        $this->entityManager->persist($log);
        $this->entityManager->flush();

        $this->entityManager->getConnection()->executeStatement(
            'UPDATE activity_log SET created_at = :d WHERE id = :id',
            ['d' => (new \DateTimeImmutable($ageModifier))->format('Y-m-d H:i:s'), 'id' => $log->getId()],
        );

        return $log->getId();
    }

    public function testPurgesApiTracesAndAuditLinesPastOneMonth(): void
    {
        $oldApi = $this->persistLogAged('api.helloasso', '-40 days');       // > 30d -> deleted
        $recentApi = $this->persistLogAged('api.cyclos', '-2 days');        // < 30d -> kept
        $recentAudit = $this->persistLogAged('client.update', '-10 days');  // < 30d -> kept
        $oldAudit = $this->persistLogAged('user.login', '-40 days');        // > 30d -> deleted

        $command = (new Application(self::$kernel))->find('app:activity-log:purge');
        $exitCode = (new CommandTester($command))->execute([]);

        self::assertSame(0, $exitCode);

        $remaining = array_map(static fn (ActivityLog $l) => $l->getId(), $this->repository->findRecent(50));
        sort($remaining);
        $expected = [$recentApi, $recentAudit];
        sort($expected);

        self::assertSame($expected, $remaining);
    }
}
