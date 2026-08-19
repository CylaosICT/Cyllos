<?php

namespace App\Repository;

use App\Entity\Client;
use App\Entity\EmailAlias;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EmailAlias>
 */
class EmailAliasRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailAlias::class);
    }

    public function findOneByClientAndSourceEmail(Client $client, string $sourceEmail): ?EmailAlias
    {
        return $this->findOneBy([
            'client' => $client,
            'sourceEmail' => strtolower(trim($sourceEmail)),
        ]);
    }

    /**
     * @return EmailAlias[]
     */
    public function findAllForClient(Client $client): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.client = :client')
            ->setParameter('client', $client)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Builds a "clientId|sourceEmail" => true lookup covering every rule for
     * the given clients, for cheaply flagging which payments already have a
     * rule (e.g. on the payment list) without one query per row.
     *
     * @param int[] $clientIds
     * @return array<string, true>
     */
    public function findSourceEmailSetForClients(array $clientIds): array
    {
        if ($clientIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('a')
            ->select('IDENTITY(a.client) AS clientId', 'a.sourceEmail AS sourceEmail')
            ->andWhere('a.client IN (:clientIds)')
            ->setParameter('clientIds', $clientIds)
            ->getQuery()
            ->getArrayResult();

        $set = [];
        foreach ($rows as $row) {
            $set[$row['clientId'] . '|' . $row['sourceEmail']] = true;
        }

        return $set;
    }
}
