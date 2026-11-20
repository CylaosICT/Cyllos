<?php

namespace App\Deployment;

use App\Backup\DatabaseBackupManager;
use App\Entity\DatabaseBackup;
use App\Entity\UpdateRun;
use App\Repository\UpdateRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Starts updates and keeps their history up to date.
 *
 * - Cyllos: the deployment runs in a detached "app:deploy:run" process, so it
 *   survives the end of the web request and the browser can follow its log.
 * - Cyllos-Pay: the payment site updates itself and reports its progress; the
 *   history entry is refreshed from that report each time the page polls.
 */
class UpdateLauncher
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UpdateRunRepository $runs,
        private readonly UpdateLog $log,
        private readonly PaySiteUpdater $paySiteUpdater,
        private readonly DatabaseBackupManager $backupManager,
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return UpdateRun|null null when an update of Cyllos is already running
     */
    public function startApp(?string $triggeredBy): ?UpdateRun
    {
        if ($this->runs->findRunning(UpdateRun::TARGET_APP) !== null) {
            return null;
        }

        $run = new UpdateRun(UpdateRun::TARGET_APP, $triggeredBy);
        $this->entityManager->persist($run);
        $this->entityManager->flush();
        $this->log->replace((int) $run->getId(), "Démarrage de la mise à jour…\n");

        $php = (new PhpExecutableFinder())->find(false) ?: 'php';
        $arguments = [$php, $this->projectDir . '/bin/console', 'app:deploy:run', (string) $run->getId(), '--env=' . ($_SERVER['APP_ENV'] ?? 'prod')];

        $command = '\\' === \DIRECTORY_SEPARATOR
            ? 'start /B "" ' . implode(' ', array_map(static fn (string $a) => '"' . $a . '"', $arguments)) . ' > NUL 2>&1'
            : 'nohup ' . implode(' ', array_map('escapeshellarg', $arguments)) . ' > /dev/null 2>&1 &';

        Process::fromShellCommandline($command, $this->projectDir)->run();

        return $run;
    }

    /**
     * @return UpdateRun|null null when the payment site was already updating
     *
     * @throws \RuntimeException when the payment site cannot be reached or refuses
     */
    public function startPay(?string $triggeredBy): ?UpdateRun
    {
        $before = $this->paySiteUpdater->status();

        $run = new UpdateRun(UpdateRun::TARGET_PAY, $triggeredBy);
        $run->setFromCommit($before->localCommit);
        $this->entityManager->persist($run);
        $this->entityManager->flush();
        $runId = (int) $run->getId();
        $this->log->replace($runId, '═══ Mise à jour de Cyllos-Pay — lancée par ' . ($triggeredBy ?? 'inconnu') . " ═══\n\n");

        // The payment site reads its data from this database: back it up first.
        $this->log->append($runId, "▶ Sauvegarde de la base de données\n");
        try {
            $backup = $this->backupManager->create(DatabaseBackup::TYPE_PRE_UPDATE, $triggeredBy);
            $run->setDatabaseBackup($backup);
            $this->entityManager->flush();
            $this->log->append($runId, '✔ ' . $backup->getFilename() . "\n\n");
        } catch (\Throwable $e) {
            $this->log->append($runId, '✘ ' . $e->getMessage() . "\nMise à jour annulée : rien n'a été modifié.\n");
            $run->finish(UpdateRun::STATUS_FAILED);
            $this->entityManager->flush();

            return $run;
        }

        $this->log->append($runId, "▶ Demande de mise à jour envoyée au site de paiement\n");
        try {
            $started = $this->paySiteUpdater->deploy();
        } catch (\RuntimeException $e) {
            $this->log->append($runId, '✘ ' . $e->getMessage() . "\n");
            $run->finish(UpdateRun::STATUS_FAILED);
            $this->entityManager->flush();

            throw $e;
        }

        if (!$started) {
            $this->log->append($runId, "✘ Une mise à jour est déjà en cours sur le site de paiement.\n");
            $run->finish(UpdateRun::STATUS_FAILED);
            $this->entityManager->flush();

            return null;
        }

        return $run;
    }

    /**
     * Refreshes a running payment-site update from the site's own report.
     */
    public function syncPay(UpdateRun $run): void
    {
        if ($run->getTarget() !== UpdateRun::TARGET_PAY || !$run->isRunning()) {
            return;
        }

        $status = $this->paySiteUpdater->status();
        if ($status->state === PaySiteStatus::STATE_UNKNOWN && $status->errorMessage !== null) {
            return; // transient: keep waiting
        }

        $header = $this->headerOf($run);
        $this->log->replace((int) $run->getId(), $header . $status->log . "\n");

        if (!$status->running) {
            $run->setToCommit($status->localCommit);
            $run->finish($status->lastRun === 'ok' ? UpdateRun::STATUS_SUCCESS : UpdateRun::STATUS_FAILED);
            $this->entityManager->flush();
            $this->log->append((int) $run->getId(), $status->lastRun === 'ok' ? "\n═══ Mise à jour terminée avec succès ═══\n" : "\n✘ La mise à jour du site de paiement a échoué.\n");
        }
    }

    /**
     * The lines Cyllos itself wrote before handing over to the payment site.
     */
    private function headerOf(UpdateRun $run): string
    {
        $text = $this->log->read((int) $run->getId(), 0)['chunk'];
        $marker = "▶ Demande de mise à jour envoyée au site de paiement\n";
        $position = strpos($text, $marker);

        return $position === false ? $text : substr($text, 0, $position + \strlen($marker)) . "\n";
    }
}
