<?php

namespace App\Controller\Dev;

use App\Backup\BackupSettings;
use App\Backup\DatabaseBackupManager;
use App\Entity\DatabaseBackup;
use App\Repository\DatabaseBackupRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/dev/sauvegardes', name: 'dev_backup_')]
#[IsGranted('ROLE_DEVELOPER')]
class BackupController extends AbstractController
{
    public function __construct(
        private readonly DatabaseBackupRepository $backups,
        private readonly DatabaseBackupManager $manager,
        private readonly BackupSettings $settings,
    ) {
    }

    #[Route(path: '', name: 'list', methods: ['GET'])]
    public function list(): Response
    {
        $backups = $this->backups->findAllNewestFirst();

        return $this->render('dev/backup/list.html.twig', [
            'backups' => $backups,
            'fileExists' => array_combine(
                array_map(static fn (DatabaseBackup $b) => $b->getId(), $backups),
                array_map($this->manager->exists(...), $backups),
            ),
            'autoEnabled' => $this->settings->isEnabled(),
            'autoInterval' => $this->settings->intervalHours(),
            'autoRetention' => $this->settings->retention(),
            'intervals' => BackupSettings::INTERVALS,
            'totalSize' => array_sum(array_map(static fn (DatabaseBackup $b) => $b->getSizeBytes(), $backups)),
        ]);
    }

    #[Route(path: '/creer', name: 'create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if ($this->isCsrfTokenValid('backup_create', $request->request->get('_token'))) {
            try {
                $backup = $this->manager->create(DatabaseBackup::TYPE_MANUAL, $this->getUser()?->getUserIdentifier());
                $this->addFlash('success', 'Sauvegarde créée : ' . $backup->getFilename());
            } catch (\RuntimeException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->redirectToRoute('dev_backup_list');
    }

    #[Route(path: '/{id<\d+>}/telecharger', name: 'download', methods: ['GET'])]
    public function download(DatabaseBackup $backup): Response
    {
        if (!$this->manager->exists($backup)) {
            $this->addFlash('error', 'Le fichier de cette sauvegarde n\'existe plus.');

            return $this->redirectToRoute('dev_backup_list');
        }

        $response = new BinaryFileResponse($this->manager->path($backup));
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $backup->getFilename());

        return $response;
    }

    #[Route(path: '/{id<\d+>}/supprimer', name: 'delete', methods: ['POST'])]
    public function delete(DatabaseBackup $backup, Request $request): Response
    {
        if ($this->isCsrfTokenValid('backup_delete_' . $backup->getId(), $request->request->get('_token'))) {
            $this->manager->delete($backup);
            $this->addFlash('success', 'Sauvegarde supprimée.');
        }

        return $this->redirectToRoute('dev_backup_list');
    }

    #[Route(path: '/automatique', name: 'settings', methods: ['POST'])]
    public function saveSettings(Request $request): Response
    {
        if ($this->isCsrfTokenValid('backup_settings', $request->request->get('_token'))) {
            $this->settings->save(
                $request->request->getBoolean('enabled'),
                $request->request->getInt('interval', 24),
                $request->request->getInt('retention', 7),
            );
            $this->addFlash('success', 'Réglages des sauvegardes automatiques enregistrés.');
        }

        return $this->redirectToRoute('dev_backup_list');
    }
}
