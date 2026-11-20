<?php

namespace App\Tests\Unit\Deployment;

use App\Backup\CodeSnapshot;
use App\Backup\DatabaseBackupManager;
use App\Deployment\DeploymentRunner;
use App\Deployment\UpdateLog;
use App\Entity\UpdateRun;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * A second deploy must not run git pull / composer / migrations on top of one
 * already in progress: DeploymentRunner takes an exclusive non-blocking lock on
 * var/deploy.lock and, when it can't get it, fails the update before taking any
 * backup or running any command.
 */
class DeploymentRunnerLockTest extends TestCase
{
    private string $projectDir;

    /** @var resource */
    private $heldLock;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/cyllos-deploy-' . bin2hex(random_bytes(6));
        mkdir($this->projectDir . '/var', 0777, true);
        $this->heldLock = fopen($this->projectDir . '/var/deploy.lock', 'c');
        flock($this->heldLock, LOCK_EX);
    }

    protected function tearDown(): void
    {
        flock($this->heldLock, LOCK_UN);
        fclose($this->heldLock);
        @unlink($this->projectDir . '/var/deploy.lock');
        array_map('unlink', glob($this->projectDir . '/var/updates/*') ?: []);
        @rmdir($this->projectDir . '/var/updates');
        @rmdir($this->projectDir . '/var');
        @rmdir($this->projectDir);
    }

    public function testExecuteFailsTheRunWhenTheLockIsAlreadyHeld(): void
    {
        $backups = $this->createMock(DatabaseBackupManager::class);
        $backups->expects(self::never())->method('create');
        $snapshot = $this->createMock(CodeSnapshot::class);
        $snapshot->expects(self::never())->method('create');
        $log = new UpdateLog($this->projectDir);

        $runner = new DeploymentRunner($this->projectDir, $this->createMock(EntityManagerInterface::class), $log, $backups, $snapshot);
        $run = new UpdateRun(UpdateRun::TARGET_APP, 'dev@example.test');

        $runner->execute($run);

        self::assertSame(UpdateRun::STATUS_FAILED, $run->getStatus());
        self::assertStringContainsString('déjà en cours', $log->read(0)['chunk']);
    }
}
