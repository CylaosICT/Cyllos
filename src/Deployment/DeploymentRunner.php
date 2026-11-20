<?php

namespace App\Deployment;

use App\Backup\CodeSnapshot;
use App\Backup\DatabaseBackupManager;
use App\Entity\DatabaseBackup;
use App\Entity\UpdateRun;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Process\Process;

/**
 * Runs a fixed, whitelisted sequence of deploy commands — never arbitrary
 * shell input — and narrates it line by line in the update's log file, which
 * the back-office shows as a live terminal.
 *
 * Safety net: before touching anything it takes a database backup and a light
 * code archive; if a step fails it puts the previous commit back (git reset)
 * and, when migrations had started, restores the database from that backup.
 *
 * Security note: letting an authenticated web action rewrite the app's own
 * source code and run new code from it is a real elevation-of-privilege
 * surface. It is accepted as a deliberate trade-off for a small internal tool.
 * Each step runs a fixed binary with fixed arguments (nothing from user input),
 * and it is only reachable by developers/CEO.
 *
 * Concurrency: guarded by an exclusive lock on var/deploy.lock.
 */
class DeploymentRunner
{
    private const TIMEOUT_SECONDS = 300.0;
    private const MIGRATION_STEP = 'migrations';

    public function __construct(
        private readonly string $projectDir,
        private readonly EntityManagerInterface $entityManager,
        private readonly UpdateLog $log,
        private readonly DatabaseBackupManager $backupManager,
        private readonly CodeSnapshot $codeSnapshot,
    ) {
    }

    /**
     * Executes the update of an UpdateRun created in "running" state. Always
     * leaves the run in a final state.
     */
    public function execute(UpdateRun $run): void
    {
        $runId = (int) $run->getId();

        $lockHandle = @fopen($this->projectDir . '/var/deploy.lock', 'c');
        if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
            if (\is_resource($lockHandle)) {
                fclose($lockHandle);
            }
            $this->log->append($runId, "✘ Un déploiement est déjà en cours sur ce serveur.\n");
            $this->finish($run, UpdateRun::STATUS_FAILED);

            return;
        }

        try {
            $this->runSequence($run);
        } catch (\Throwable $e) {
            $this->say($runId, '✘ Erreur inattendue : ' . $e->getMessage());
            $this->finish($run, UpdateRun::STATUS_FAILED);
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    private function runSequence(UpdateRun $run): void
    {
        $runId = (int) $run->getId();

        $this->say($runId, '═══ Mise à jour de Cyllos — lancée par ' . ($run->getTriggeredBy() ?? 'inconnu') . ' ═══');

        // --- Safety backups -------------------------------------------------
        $this->say($runId, '', '▶ Sauvegarde de la base de données');
        try {
            $backup = $this->backupManager->create(DatabaseBackup::TYPE_PRE_UPDATE, $run->getTriggeredBy());
            $run->setDatabaseBackup($backup);
            $this->say($runId, \sprintf('✔ %s (%s)', $backup->getFilename(), self::formatSize($backup->getSizeBytes())));
        } catch (\Throwable $e) {
            $this->say($runId, '✘ ' . $e->getMessage(), 'Mise à jour annulée : rien n\'a été modifié.');
            $this->finish($run, UpdateRun::STATUS_FAILED);

            return;
        }

        $this->say($runId, '', '▶ Archive du code actuel');
        try {
            $snapshot = $this->codeSnapshot->create();
            $run->setCodeBackup($snapshot['file'], $snapshot['size']);
            $this->say($runId, \sprintf('✔ %s (%s)', $snapshot['file'], self::formatSize($snapshot['size'])));
        } catch (\Throwable $e) {
            $this->say($runId, '✘ ' . $e->getMessage(), 'Mise à jour annulée : rien n\'a été modifié.');
            $this->finish($run, UpdateRun::STATUS_FAILED);

            return;
        }

        $from = $this->git(['rev-parse', 'HEAD']);
        $run->setFromCommit($from);
        $this->entityManager->flush();

        // --- Deployment steps ----------------------------------------------
        $steps = [
            ['key' => 'pull', 'label' => 'Récupération du code', 'command' => ['git', 'pull', '--ff-only']],
            ['key' => 'composer', 'label' => 'Installation des dépendances', 'command' => ['composer', 'install', '--no-interaction']],
            ['key' => self::MIGRATION_STEP, 'label' => 'Application des migrations', 'command' => ['php', 'bin/console', 'doctrine:migrations:migrate', '--no-interaction']],
            ['key' => 'cache', 'label' => 'Vidage du cache', 'command' => ['php', 'bin/console', 'cache:clear', '--no-interaction']],
            // Recompiles AssetMapper's manifest: without it, a deploy that ships a
            // brand new JS/CSS file leaves prod serving the old manifest.
            ['key' => 'assets', 'label' => 'Compilation des assets', 'command' => ['php', 'bin/console', 'asset-map:compile', '--no-interaction']],
            // Best-effort: reload the workers (needs a passwordless sudoers rule).
            ['key' => 'workers', 'label' => 'Redémarrage des workers', 'command' => ['sudo', '-n', 'systemctl', 'restart', 'cyllos-worker', 'cyllos-scheduler'], 'optional' => true],
        ];

        $migrationsStarted = false;
        $failedAt = null;

        foreach ($steps as $step) {
            $optional = $step['optional'] ?? false;
            $this->say($runId, '', '▶ ' . $step['label']);

            if ($step['key'] === self::MIGRATION_STEP) {
                $migrationsStarted = true;
            }

            $ok = $this->runCommand($runId, $step['command']);

            if ($step['key'] === 'pull' && $ok) {
                $this->recordCommits($run, $from);
            }

            if (!$ok && $optional) {
                $this->say($runId, '⚠ Étape facultative ignorée. À faire manuellement : sudo systemctl restart cyllos-worker cyllos-scheduler');

                continue;
            }

            if (!$ok) {
                $failedAt = $step['label'];

                break;
            }
        }

        if ($failedAt === null) {
            $this->say($runId, '', '═══ Mise à jour terminée avec succès ═══');
            $this->finish($run, UpdateRun::STATUS_SUCCESS);

            return;
        }

        $this->say($runId, '', '✘ Échec à l\'étape « ' . $failedAt . ' ». Retour à la version précédente…');
        $restored = $this->rollback($run, $from, $migrationsStarted);
        $this->say($runId, '', $restored ? '═══ Version précédente restaurée ═══' : '═══ Restauration incomplète : intervention manuelle nécessaire ═══');
        $this->finish($run, $restored ? UpdateRun::STATUS_ROLLED_BACK : UpdateRun::STATUS_ROLLBACK_FAILED);
    }

    private function rollback(UpdateRun $run, ?string $from, bool $migrationsStarted): bool
    {
        $runId = (int) $run->getId();
        $ok = true;

        if ($from !== null) {
            $this->say($runId, '', '▶ Restauration du code');
            if (!$this->runCommand($runId, ['git', 'reset', '--hard', $from])) {
                $ok = false;
                $snapshot = $run->getCodeBackupFile();
                if ($snapshot !== null && $this->codeSnapshot->exists($snapshot)) {
                    $this->say($runId, 'Tentative depuis l\'archive du code…');
                    $ok = $this->runCommand($runId, ['tar', '-xzf', $this->codeSnapshot->path($snapshot), '-C', $this->projectDir]);
                }
            }
            $this->runCommand($runId, ['composer', 'install', '--no-interaction']) || $ok = false;
        } else {
            $this->say($runId, '⚠ Commit de départ inconnu : le code n\'a pas pu être restauré.');
            $ok = false;
        }

        $backup = $run->getDatabaseBackup();
        if ($migrationsStarted && $backup !== null) {
            $this->say($runId, '', '▶ Restauration de la base de données');
            try {
                $this->say($runId, '$ restauration de ' . $backup->getFilename());
                $this->backupManager->restore($backup);
                $this->say($runId, '✔ base restaurée');
            } catch (\Throwable $e) {
                $this->say($runId, '✘ ' . $e->getMessage());
                $ok = false;
            }
        }

        $this->say($runId, '', '▶ Remise en état');
        $this->runCommand($runId, ['php', 'bin/console', 'cache:clear', '--no-interaction']) || $ok = false;
        $this->runCommand($runId, ['php', 'bin/console', 'asset-map:compile', '--no-interaction']) || $ok = false;

        return $ok;
    }

    private function recordCommits(UpdateRun $run, ?string $from): void
    {
        $to = $this->git(['rev-parse', 'HEAD']);
        $run->setToCommit($to);

        if ($from !== null && $to !== null && $from !== $to) {
            $output = $this->git(['log', '--pretty=format:%H%x09%an%x09%s', $from . '..' . $to]) ?? '';
            $commits = [];
            foreach (array_filter(explode("\n", $output)) as $line) {
                [$sha, $author, $subject] = array_pad(explode("\t", $line, 3), 3, '');
                $commits[] = ['sha' => $sha, 'author' => $author, 'subject' => $subject];
            }
            $run->setCommits($commits);
        }

        $this->entityManager->flush();
    }

    /**
     * Runs one command, echoing it and its output to the log as it goes.
     *
     * @param list<string> $command
     */
    private function runCommand(int $runId, array $command): bool
    {
        $this->log->append($runId, '$ ' . implode(' ', $command) . "\n");

        $process = new Process($command, $this->projectDir, null, null, self::TIMEOUT_SECONDS);
        try {
            $process->run(function (string $type, string $buffer) use ($runId): void {
                $this->log->append($runId, $buffer);
            });
        } catch (\Throwable $e) {
            $this->log->append($runId, '✘ ' . $e->getMessage() . "\n");

            return false;
        }

        if ($process->isSuccessful()) {
            $this->log->append($runId, "✔ terminé\n");

            return true;
        }

        $this->log->append($runId, \sprintf("✘ échec (code %s)\n", $process->getExitCode() ?? '?'));

        return false;
    }

    /**
     * @param list<string> $arguments
     */
    private function git(array $arguments): ?string
    {
        $process = new Process(['git', ...$arguments], $this->projectDir, null, null, 30);
        $process->run();

        return $process->isSuccessful() ? trim($process->getOutput()) : null;
    }

    private function say(int $runId, string ...$lines): void
    {
        $this->log->append($runId, implode("\n", $lines) . "\n");
    }

    private function finish(UpdateRun $run, string $status): void
    {
        $run->finish($status);
        $this->entityManager->flush();
    }

    public static function formatSize(int $bytes): string
    {
        return match (true) {
            $bytes >= 1048576 => round($bytes / 1048576, 1) . ' Mo',
            $bytes >= 1024 => round($bytes / 1024) . ' Ko',
            default => $bytes . ' o',
        };
    }
}
