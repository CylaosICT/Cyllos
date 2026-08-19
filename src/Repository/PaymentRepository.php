<?php

namespace App\Repository;

use App\Entity\Client;
use App\Entity\Payment;
use App\Entity\PaymentStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Payment>
 */
class PaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Payment::class);
    }

    public function findOneByClientAndHelloAssoId(Client $client, int $helloAssoPaymentId): ?Payment
    {
        return $this->findOneBy([
            'client' => $client,
            'helloAssoPaymentId' => $helloAssoPaymentId,
        ]);
    }

    /**
     * @return Payment[]
     */
    public function findAllForClient(Client $client): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.client = :client')
            ->setParameter('client', $client)
            ->orderBy('p.paymentDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Paginated payment list, optionally scoped to a client and/or a set of
     * statuses, with the Client relation eager-loaded to avoid an N+1 query
     * per row when the list displays the client name (admin cross-client
     * view).
     *
     * @param PaymentStatus[] $statuses
     * @return array{items: Payment[], total: int, page: int, perPage: int, pageCount: int}
     */
    public function paginate(?Client $client, int $page, int $perPage, array $statuses = []): array
    {
        $page = max(1, $page);

        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.client', 'c')
            ->addSelect('c')
            ->orderBy('p.paymentDate', 'DESC');

        if ($client !== null) {
            $qb->andWhere('p.client = :client')->setParameter('client', $client);
        }

        if ($statuses !== []) {
            $qb->andWhere('p.status IN (:statuses)')->setParameter('statuses', $statuses);
        }

        $total = (int) (clone $qb)
            ->select('COUNT(p.id)')
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
     * @return int[]
     */
    public function findAllHelloAssoIdsForClient(Client $client): array
    {
        $result = $this->createQueryBuilder('p')
            ->select('p.helloAssoPaymentId')
            ->andWhere('p.client = :client')
            ->setParameter('client', $client)
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row) => (int) $row['helloAssoPaymentId'], $result);
    }

    /**
     * @return Payment[]
     */
    public function findByStatus(PaymentStatus $status, ?Client $client = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.status = :status')
            ->setParameter('status', $status);

        if ($client !== null) {
            $qb->andWhere('p.client = :client')->setParameter('client', $client);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Ids eligible for purge: only payments actually credited in Cyclos
     * (Success/SuccessAuto). Anything not yet processed, or that errored out
     * (Fail, TooHigh, TooLate, Waiting, Todo), is kept indefinitely — it's the
     * only record of an unresolved payment, and a client dispute needs it to
     * still exist no matter how old it is.
     *
     * @return int[]
     */
    public function findPurgeableIdsByInsertionDateBefore(\DateTimeImmutable $date): array
    {
        $result = $this->createQueryBuilder('p')
            ->select('p.id')
            ->andWhere('p.insertionDate < :date')
            ->andWhere('p.status IN (:statuses)')
            ->setParameter('date', $date)
            ->setParameter('statuses', [PaymentStatus::Success, PaymentStatus::SuccessAuto])
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row) => (int) $row['id'], $result);
    }

    /**
     * Matches individual fields (first name, last name, email) as well as the
     * concatenated "firstName lastName" pair, so a full-name search like
     * "Eric DE BEL-AIR" matches even though neither field alone contains it.
     *
     * @return Payment[]
     */
    public function search(string $query, int $limit = 8): array
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.client', 'c')
            ->addSelect('c')
            ->andWhere(
                'p.payerFirstName LIKE :q OR p.payerLastName LIKE :q OR p.email LIKE :q'
                . " OR CONCAT(p.payerFirstName, ' ', p.payerLastName) LIKE :q"
                . " OR CONCAT(p.payerLastName, ' ', p.payerFirstName) LIKE :q"
            )
            ->setParameter('q', '%' . $query . '%')
            ->orderBy('p.paymentDate', 'DESC')
            ->setMaxResults($limit);

        if (ctype_digit($query)) {
            $qb->orWhere('p.helloAssoPaymentId = :id')->setParameter('id', (int) $query);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @param int[] $ids
     */
    public function deleteByIds(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $this->createQueryBuilder('p')
            ->delete()
            ->andWhere('p.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
    }
}
