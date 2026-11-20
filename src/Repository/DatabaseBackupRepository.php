<?php

namespace App\Repository;

use App\Entity\DatabaseBackup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DatabaseBackup>
 */
class DatabaseBackupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DatabaseBackup::class);
    }

    /**
     * @return DatabaseBackup[]
     */
    public function findAllNewestFirst(): array
    {
        return $this->findBy([], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    public function findLatestOfType(string $type): ?DatabaseBackup
    {
        return $this->findOneBy(['type' => $type], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }
}
