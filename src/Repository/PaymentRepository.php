<?php

namespace App\Repository;

use App\Entity\Client;
use App\Entity\HelloAssoConfig;
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
     * Whether any payment references this config — a config with history can
     * only be deactivated, never deleted (see ClientController::deleteHelloAssoConfig()).
     */
    public function hasAnyForHelloAssoConfig(HelloAssoConfig $config): bool
    {
        return $this->count(['helloAssoConfig' => $config]) > 0;
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
     * statuses, with the Client and HelloAssoConfig relations eager-loaded to
     * avoid an N+1 query per row when the list displays the client name and
     * form label (admin cross-client view).
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
            ->leftJoin('p.helloAssoConfig', 'hac')
            ->addSelect('hac')
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
     * Number of payments still "todo" past $threshold that belong to a client with
     * automatic crediting enabled. For such a client a webhook payment should be
     * picked up by the worker within seconds and leave the "todo" state; a growing
     * count here means the async worker is not consuming (see /health).
     */
    public function countStuckAutomaticTodoPayments(\DateTimeImmutable $threshold): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->join('p.client', 'c')
            ->join('c.setting', 's')
            ->andWhere('p.status = :todo')
            ->andWhere('s.paymentAutomaticEnabled = true')
            ->andWhere('p.insertionDate < :threshold')
            ->setParameter('todo', PaymentStatus::Todo)
            ->setParameter('threshold', $threshold)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Payment counts grouped by status for payments inserted on/after $since,
     * optionally scoped to a single client (client dashboard). Statuses with no
     * payment in the window are simply absent from the map.
     *
     * @return array<string, int> status value => count
     */
    public function countByStatusSince(\DateTimeImmutable $since, ?Client $client = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('p.status AS status, COUNT(p.id) AS c')
            ->andWhere('p.insertionDate >= :since')
            ->setParameter('since', $since)
            ->groupBy('p.status');

        if ($client !== null) {
            $qb->andWhere('p.client = :client')->setParameter('client', $client);
        }

        $counts = [];
        foreach ($qb->getQuery()->getResult() as $row) {
            $status = $row['status'];
            $counts[$status instanceof PaymentStatus ? $status->value : (string) $status] = (int) $row['c'];
        }

        return $counts;
    }

    /**
     * Sum of payment amounts grouped by status for payments inserted on/after
     * $since ("money" figures), optionally scoped to a single client. Absent
     * statuses mean zero.
     *
     * @return array<string, float> status value => summed amount
     */
    public function sumAmountByStatusSince(\DateTimeImmutable $since, ?Client $client = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('p.status AS status, SUM(p.amount) AS total')
            ->andWhere('p.insertionDate >= :since')
            ->setParameter('since', $since)
            ->groupBy('p.status');

        if ($client !== null) {
            $qb->andWhere('p.client = :client')->setParameter('client', $client);
        }

        $totals = [];
        foreach ($qb->getQuery()->getResult() as $row) {
            // p.status is an enumType mapping, so getResult() hydrates it to a PaymentStatus.
            $totals[$row['status']->value] = (float) $row['total'];
        }

        return $totals;
    }

    /**
     * One aggregate row per active client for the dashboard's per-client table:
     * payment counts (total / credited / failed / to-handle) and credited amount
     * over the window, plus the client's mode flags. Clients with no payment in
     * the window are still listed (zeros) — a client that just went quiet is
     * exactly what an admin wants to spot. "lastPaymentAt" here is scoped to the
     * window; the caller fills in the last-ever date separately.
     *
     * @return list<array{clientId: int, name: string, total: int, credited: int, failed: int, toHandle: int, amountCredited: float, cyclosEnabled: bool, automaticEnabled: bool}>
     */
    public function perClientStatsSince(\DateTimeImmutable $since): array
    {
        $credited = PaymentStatus::credited();
        $toHandle = PaymentStatus::toHandle();

        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select(
                'c.id AS clientId',
                'c.name AS name',
                'COUNT(p.id) AS total',
                'SUM(CASE WHEN p.status IN (:credited) THEN 1 ELSE 0 END) AS credited',
                'SUM(CASE WHEN p.status = :fail THEN 1 ELSE 0 END) AS failed',
                'SUM(CASE WHEN p.status IN (:toHandle) THEN 1 ELSE 0 END) AS toHandle',
                'SUM(CASE WHEN p.status IN (:credited) THEN p.amount ELSE 0 END) AS amountCredited',
                's.paymentCyclosEnabled AS cyclosEnabled',
                's.paymentAutomaticEnabled AS automaticEnabled',
            )
            ->from(Client::class, 'c')
            ->leftJoin('c.payments', 'p', 'WITH', 'p.insertionDate >= :since')
            ->leftJoin('c.setting', 's')
            ->andWhere('c.active = true')
            ->groupBy('c.id')
            ->addGroupBy('c.name')
            ->addGroupBy('s.paymentCyclosEnabled')
            ->addGroupBy('s.paymentAutomaticEnabled')
            ->orderBy('c.name', 'ASC')
            ->setParameter('since', $since)
            ->setParameter('credited', $credited)
            ->setParameter('fail', PaymentStatus::Fail)
            ->setParameter('toHandle', $toHandle)
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): array => [
            'clientId' => (int) $row['clientId'],
            'name' => (string) $row['name'],
            'total' => (int) $row['total'],
            'credited' => (int) $row['credited'],
            'failed' => (int) $row['failed'],
            'toHandle' => (int) $row['toHandle'],
            'amountCredited' => (float) $row['amountCredited'],
            'cyclosEnabled' => (bool) $row['cyclosEnabled'],
            'automaticEnabled' => (bool) $row['automaticEnabled'],
        ], $rows);
    }

    /**
     * Last payment insertion date per client id, all history (no window) —
     * merged into perClientStatsSince() rows for the "last payment" column.
     *
     * @return array<int, \DateTimeImmutable>
     */
    public function lastPaymentAtByClient(): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('IDENTITY(p.client) AS clientId, MAX(p.insertionDate) AS lastAt')
            ->groupBy('p.client')
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            // MAX() comes back as a string, never a hydrated DateTimeImmutable.
            $map[(int) $row['clientId']] = new \DateTimeImmutable((string) $row['lastAt']);
        }

        return $map;
    }

    /**
     * Most recent payments in a given status, client eager-loaded (admin dashboard
     * "latest failures" panel).
     *
     * @return Payment[]
     */
    public function findRecentByStatus(PaymentStatus $status, int $limit = 5, ?Client $client = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.client', 'c')
            ->addSelect('c')
            ->leftJoin('p.helloAssoConfig', 'hac')
            ->addSelect('hac')
            ->andWhere('p.status = :status')
            ->setParameter('status', $status)
            ->orderBy('p.insertionDate', 'DESC')
            ->setMaxResults($limit);

        if ($client !== null) {
            $qb->andWhere('p.client = :client')->setParameter('client', $client);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Most recent payments for a client, any status (client dashboard "latest
     * payments" panel).
     *
     * @return Payment[]
     */
    public function findRecentForClient(Client $client, int $limit = 8): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.helloAssoConfig', 'hac')
            ->addSelect('hac')
            ->andWhere('p.client = :client')
            ->setParameter('client', $client)
            ->orderBy('p.insertionDate', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
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
            ->setParameter('statuses', PaymentStatus::credited())
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row) => (int) $row['id'], $result);
    }

    /**
     * Matches individual fields (first name, last name, emails, form label,
     * client name) as well as the
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
            ->leftJoin('p.helloAssoConfig', 'hac')
            ->addSelect('hac')
            ->andWhere(
                'p.payerFirstName LIKE :q OR p.payerLastName LIKE :q OR p.email LIKE :q OR p.payerEmail LIKE :q OR hac.label LIKE :q OR c.name LIKE :q'
                . " OR CONCAT(p.payerFirstName, ' ', p.payerLastName) LIKE :q"
                . " OR CONCAT(p.payerLastName, ' ', p.payerFirstName) LIKE :q",
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
