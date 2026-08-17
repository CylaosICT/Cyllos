<?php

namespace App\ActivityLog;

use App\Entity\ActivityLog;
use App\Entity\Client;
use App\Entity\Payment;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostRemoveEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Generic audit trail: records create/update/delete of the entities that
 * matter operationally (Client, Payment, User), without leaking secret or
 * password values into the log (only changed field names are recorded).
 *
 * New ActivityLog rows can't be persisted from inside postPersist/postUpdate
 * (mid-flush), so entries are queued and flushed separately in postFlush.
 */
#[AsDoctrineListener(event: Events::postPersist)]
#[AsDoctrineListener(event: Events::postUpdate)]
#[AsDoctrineListener(event: Events::postRemove)]
#[AsDoctrineListener(event: Events::postFlush)]
class ActivityLogSubscriber
{
    private const REDACTED_FIELDS = ['password', 'clientSecretEncrypted', 'passwordEncrypted'];

    /** @var array<int, array{action: string, summary: string, context: array}> */
    private array $pending = [];

    private bool $isFlushingLog = false;

    public function __construct(
        private readonly Security $security,
    ) {
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();
        $label = $this->labelFor($entity);
        if ($label === null) {
            return;
        }

        $this->pending[] = [
            'action' => $label . '.created',
            'summary' => $this->summarize($entity),
            'context' => [],
        ];
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        $label = $this->labelFor($entity);
        if ($label === null) {
            return;
        }

        $changeSet = $args->getObjectManager()->getUnitOfWork()->getEntityChangeSet($entity);
        $changedFields = array_values(array_diff(array_keys($changeSet), self::REDACTED_FIELDS));
        if ($changedFields === []) {
            return;
        }

        $this->pending[] = [
            'action' => $label . '.updated',
            'summary' => $this->summarize($entity),
            'context' => ['changedFields' => $changedFields],
        ];
    }

    public function postRemove(PostRemoveEventArgs $args): void
    {
        $entity = $args->getObject();
        $label = $this->labelFor($entity);
        if ($label === null) {
            return;
        }

        $this->pending[] = [
            'action' => $label . '.deleted',
            'summary' => $this->summarize($entity),
            'context' => [],
        ];
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->isFlushingLog || $this->pending === []) {
            return;
        }

        $entityManager = $args->getObjectManager();
        $actorEmail = $this->currentActorEmail();

        foreach ($this->pending as $entry) {
            $log = new ActivityLog();
            $log->setAction($entry['action']);
            $log->setSummary($entry['summary']);
            $log->setActorEmail($actorEmail);
            if ($entry['context'] !== []) {
                $log->setContext(json_encode($entry['context'], JSON_THROW_ON_ERROR));
            }
            $entityManager->persist($log);
        }
        $this->pending = [];

        $this->isFlushingLog = true;
        try {
            $entityManager->flush();
        } finally {
            $this->isFlushingLog = false;
        }
    }

    private function labelFor(object $entity): ?string
    {
        return match (true) {
            $entity instanceof Client => 'client',
            $entity instanceof Payment => 'payment',
            $entity instanceof User => 'user',
            default => null,
        };
    }

    private function summarize(object $entity): string
    {
        return match (true) {
            $entity instanceof Client => $entity->getName() . ' (#' . $entity->getId() . ')',
            $entity instanceof Payment => 'Paiement #' . $entity->getHelloAssoPaymentId() . ' (' . $entity->getClient()->getName() . ')',
            $entity instanceof User => $entity->getEmail(),
            default => (string) $entity->getId(),
        };
    }

    private function currentActorEmail(): ?string
    {
        try {
            $user = $this->security->getUser();
        } catch (\Throwable) {
            return null;
        }

        return $user instanceof User ? $user->getEmail() : null;
    }
}
