<?php

namespace App\Repository;

use App\Entity\UpdateRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UpdateRun>
 */
class UpdateRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UpdateRun::class);
    }

    /**
     * @return UpdateRun[]
     */
    public function findRecent(int $limit = 100): array
    {
        return $this->findBy([], ['startedAt' => 'DESC', 'id' => 'DESC'], $limit);
    }

    public function findRunning(?string $target = null): ?UpdateRun
    {
        $criteria = ['status' => UpdateRun::STATUS_RUNNING];
        if ($target !== null) {
            $criteria['target'] = $target;
        }

        return $this->findOneBy($criteria, ['startedAt' => 'DESC']);
    }
}
