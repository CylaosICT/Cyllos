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

    public function countActive(): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.active = true')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Active clients whose most recent payment is older than $threshold (or which
     * have never received one) — a likely sign of a missing/broken HelloAsso
     * webhook configuration. Ordered oldest-signal first.
     *
     * @return list<array{client: Client, lastPaymentAt: ?\DateTimeImmutable}>
     */
    public function findActiveQuietSince(\DateTimeImmutable $threshold): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c AS client', 'MAX(p.insertionDate) AS lastPaymentAt')
            ->leftJoin('c.payments', 'p')
            ->andWhere('c.active = true')
            ->groupBy('c.id')
            ->having('MAX(p.insertionDate) < :threshold OR MAX(p.insertionDate) IS NULL')
            ->setParameter('threshold', $threshold)
            ->orderBy('lastPaymentAt', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (array $row) => [
                'client' => $row['client'],
                'lastPaymentAt' => $row['lastPaymentAt'] !== null ? new \DateTimeImmutable($row['lastPaymentAt']) : null,
            ],
            $rows,
        );
    }

    /**
     * Active clients whose HelloAsso notification URL still hits the token-less
     * legacy endpoint (see WebhookController::legacy()), seen since $since.
     * Most-recent signal first.
     *
     * @return Client[]
     */
    public function findWithRecentLegacyWebhook(\DateTimeImmutable $since): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.active = true')
            ->andWhere('c.legacyWebhookLastSeenAt >= :since')
            ->setParameter('since', $since)
            ->orderBy('c.legacyWebhookLastSeenAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Client[]
     */
    public function search(string $query, int $limit = 8): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.helloAssoConfigs', 'hac')
            ->andWhere('c.name LIKE :q OR c.slug LIKE :q OR hac.label LIKE :q OR hac.formSlug LIKE :q')
            ->setParameter('q', '%' . $query . '%')
            ->groupBy('c.id')
            ->orderBy('c.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
