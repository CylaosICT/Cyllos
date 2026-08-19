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
     * @return array{items: Client[], total: int, page: int, perPage: int, pageCount: int}
     */
    public function paginate(int $page, int $perPage): array
    {
        $page = max(1, $page);

        $qb = $this->createQueryBuilder('c')->orderBy('c.name', 'ASC');

        $total = (int) (clone $qb)
            ->select('COUNT(c.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        $items = $qb
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'pageCount' => max(1, (int) ceil($total / $perPage)),
        ];
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
