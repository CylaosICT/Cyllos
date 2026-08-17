<?php

namespace App\Repository;

use App\Entity\Client;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Client>
 */
class ClientRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Client::class);
    }

    public function findOneBySlug(string $slug): ?Client
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * @return Client[]
     */
    public function findAllActive(): array
    {
        return $this->findBy(['active' => true], ['name' => 'ASC']);
    }

    /**
     * @return Client[]
     */
    public function search(string $query, int $limit = 8): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.name LIKE :q OR c.slug LIKE :q')
            ->setParameter('q', '%' . $query . '%')
            ->orderBy('c.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
