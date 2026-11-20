<?php

namespace App\Backup;

use App\Entity\DatabaseBackup;
use App\Repository\DatabaseBackupRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Creates, restores and deletes the database backups kept under var/backups/db.
 */
class DatabaseBackupManager
{
    public function __construct(
        private readonly DatabaseDumper $dumper,
        private readonly DatabaseBackupRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $projectDir,
    ) {
    }

    public function create(string $type, ?string $createdBy = null): DatabaseBackup
    {
        $directory = $this->directory();
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('Dossier de sauvegarde inaccessible : ' . $directory);
        }

        $filename = \sprintf('cyllos-db-%s-%s.sql.gz', date('Ymd-His'), str_replace('_', '-', $type));
        $path = $directory . '/' . $filename;

        try {
            $this->dumper->dump($path);
        } catch (\Throwable $e) {
            @unlink($path);

            throw new \RuntimeException('Sauvegarde de la base impossible : ' . $e->getMessage(), 0, $e);
        }

        $backup = new DatabaseBackup($filename, $type, (int) filesize($path), $createdBy);
        $this->entityManager->persist($backup);
        $this->entityManager->flush();

        return $backup;
    }

    public function restore(DatabaseBackup $backup): void
    {
        $path = $this->path($backup);
        if (!is_file($path)) {
            throw new \RuntimeException('Le fichier de sauvegarde n\'existe plus.');
        }

        $this->dumper->restore($path);
    }

    public function delete(DatabaseBackup $backup): void
    {
        @unlink($this->path($backup));
        $this->entityManager->remove($backup);
        $this->entityManager->flush();
    }

    public function path(DatabaseBackup $backup): string
    {
        // basename(): the stored name never leads outside the backup folder.
        return $this->directory() . '/' . basename($backup->getFilename());
    }

    public function exists(DatabaseBackup $backup): bool
    {
        return is_file($this->path($backup));
    }

    /**
     * Keeps only the $keep most recent automatic backups.
     */
    public function pruneAutomatic(int $keep): int
    {
        $deleted = 0;
        $automatic = array_values(array_filter(
            $this->repository->findAllNewestFirst(),
            static fn (DatabaseBackup $b) => $b->getType() === DatabaseBackup::TYPE_AUTO,
        ));

        foreach (\array_slice($automatic, max(1, $keep)) as $old) {
            $this->delete($old);
            ++$deleted;
        }

        return $deleted;
    }

    private function directory(): string
    {
        return $this->projectDir . '/var/backups/db';
    }
}
