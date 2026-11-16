<?php

namespace App\Repository;

use App\Entity\CheckoutOrder;
use App\Entity\Client;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CheckoutOrder>
 */
class CheckoutOrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CheckoutOrder::class);
    }

    public function findOneByClientAndReference(Client $client, string $reference): ?CheckoutOrder
    {
        return $this->findOneBy(['client' => $client, 'reference' => $reference]);
    }

    public function findOneByClientAndSessionId(Client $client, string $sessionId): ?CheckoutOrder
    {
        return $this->findOneBy(['client' => $client, 'stripeSessionId' => $sessionId]);
    }

    /**
     * @return CheckoutOrder[]
     */
    public function findAllForClient(Client $client): array
    {
        return $this->findBy(['client' => $client]);
    }
}
