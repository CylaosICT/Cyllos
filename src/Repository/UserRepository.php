<?php

namespace App\Repository;

use App\Entity\Client;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    /**
     * @return User[]
     */
    public function findByClient(Client $client): array
    {
        return $this->findBy(['client' => $client], ['email' => 'ASC']);
    }

    /**
     * @return array{items: User[], total: int, page: int, perPage: int, pageCount: int}
     */
    public function paginateByClient(Client $client, int $page, int $perPage): array
    {
        return $this->paginate(['client' => $client], $page, $perPage);
    }

    /**
     * Global Cylaos team accounts (admins/developers), not tied to a client.
     *
     * @return array{items: User[], total: int, page: int, perPage: int, pageCount: int}
     */
    public function paginateGlobalTeam(int $page, int $perPage): array
    {
        return $this->paginate(['client' => null], $page, $perPage);
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array{items: User[], total: int, page: int, perPage: int, pageCount: int}
     */
    private function paginate(array $criteria, int $page, int $perPage): array
    {
        $page = max(1, $page);

        $qb = $this->createQueryBuilder('u')->orderBy('u.email', 'ASC');
        foreach ($criteria as $field => $value) {
            if ($value === null) {
                $qb->andWhere(sprintf('u.%s IS NULL', $field));
            } else {
                $qb->andWhere(sprintf('u.%s = :%s', $field, $field))->setParameter($field, $value);
            }
        }

        $total = (int) (clone $qb)
            ->select('COUNT(u.id)')
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
     * @return User[]
     */
    public function search(string $query, int $limit = 8): array
    {
        return $this->createQueryBuilder('u')
            ->andWhere('u.email LIKE :q')
            ->setParameter('q', '%' . $query . '%')
            ->orderBy('u.email', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
