<?php

namespace App\Controller\Dev;

use App\Backup\CodeSnapshot;
use App\Backup\DatabaseBackupManager;
use App\Deployment\UpdateLauncher;
use App\Deployment\UpdateLog;
use App\Deployment\VersionChecker;
use App\Entity\UpdateRun;
use App\Repository\UpdateRunRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/dev/mises-a-jour', name: 'dev_update_')]
#[IsGranted('ROLE_DEVELOPER')]
class UpdateHistoryController extends AbstractController
{
    public function __construct(
        private readonly UpdateRunRepository $runs,
        private readonly UpdateLog $log,
        private readonly UpdateLauncher $launcher,
        private readonly CodeSnapshot $codeSnapshot,
        private readonly DatabaseBackupManager $backupManager,
        private readonly VersionChecker $versionChecker,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route(path: '', name: 'list', methods: ['GET'])]
    public function list(): Response
    {
        $runs = $this->runs->findRecent(200);

        return $this->render('dev/update/list.html.twig', [
            'runs' => $runs,
            'codeBackupExists' => array_combine(
                array_map(static fn (UpdateRun $r) => $r->getId(), $runs),
                array_map(fn (UpdateRun $r) => $r->getCodeBackupFile() !== null && $this->codeSnapshot->exists($r->getCodeBackupFile()), $runs),
            ),
        ]);
    }

    #[Route(path: '/{id<\d+>}', name: 'show', methods: ['GET'])]
    public function show(UpdateRun $run): Response
    {
        return $this->render('dev/update/show.html.twig', [
            'run' => $run,
            'repo' => $this->versionChecker->check()->upstreamRepo,
            'codeBackupExists' => $run->getCodeBackupFile() !== null && $this->codeSnapshot->exists($run->getCodeBackupFile()),
            'dbBackupExists' => $run->getDatabaseBackup() !== null && $this->backupManager->exists($run->getDatabaseBackup()),
        ]);
    }

    /**
     * Polled by the terminal: new log text since "offset", and the run state.
     */
    #[Route(path: '/{id<\d+>}/flux', name: 'stream', methods: ['GET'])]
    public function logStream(UpdateRun $run, Request $request): JsonResponse
    {
        $this->launcher->syncPay($run);
        $this->entityManager->refresh($run);

        $read = $this->log->read((int) $run->getId(), max(0, $request->query->getInt('offset')));

        return new JsonResponse([
            'chunk' => $read['chunk'],
            'offset' => $read['offset'],
            'running' => $run->isRunning(),
            'status' => $run->getStatus(),
            'statusLabel' => $run->statusLabel(),
            'badge' => $run->statusBadge(),
        ]);
    }

    /**
     * Deletes the code archive of an update, to free disk space. The database
     * backup is managed from the backups page.
     */
    #[Route(path: '/{id<\d+>}/supprimer-archive-code', name: 'delete_code_backup', methods: ['POST'])]
    public function deleteCodeBackup(UpdateRun $run, Request $request): Response
    {
        if ($this->isCsrfTokenValid('update_code_backup_' . $run->getId(), $request->request->get('_token')) && $run->getCodeBackupFile() !== null) {
            $this->codeSnapshot->delete($run->getCodeBackupFile());
            $run->setCodeBackup(null, null);
            $this->entityManager->flush();
            $this->addFlash('success', 'Archive du code supprimée.');
        }

        return $this->redirect($request->headers->get('referer') ?: $this->generateUrl('dev_update_list'));
    }
}
