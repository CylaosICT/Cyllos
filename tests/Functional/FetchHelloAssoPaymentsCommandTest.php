<?php

namespace App\Tests\Functional;

use App\Entity\Client;
use App\Message\FetchClientPaymentsMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The scheduled `app:helloasso:fetch` must fan out one async message per active
 * client (not walk them inline), so one slow client cannot stall the others.
 */
class FetchHelloAssoPaymentsCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $this->entityManager->createQuery('DELETE FROM App\Entity\Payment')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\ClientSetting')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\CyclosConfig')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\CheckoutOrder')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\StripeConfig')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\HelloAssoConfig')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Client')->execute();
    }

    private function persistClient(string $slug, bool $active): Client
    {
        $client = (new Client())->setName($slug)->setSlug($slug)->setActive($active);
        $this->entityManager->persist($client);
        $this->entityManager->flush();

        return $client;
    }

    public function testDispatchesOneMessagePerActiveClient(): void
    {
        $a = $this->persistClient('client-a', active: true);
        $b = $this->persistClient('client-b', active: true);
        $this->persistClient('client-c', active: false);

        $command = (new Application(self::$kernel))->find('app:helloasso:fetch');
        $exitCode = (new CommandTester($command))->execute([]);

        self::assertSame(0, $exitCode);

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $fetchMessages = array_map(
            static fn ($envelope) => $envelope->getMessage(),
            array_filter($transport->getSent(), static fn ($e) => $e->getMessage() instanceof FetchClientPaymentsMessage),
        );

        $ids = array_map(static fn ($m) => $m->clientId, $fetchMessages);
        sort($ids);
        self::assertSame([$a->getId(), $b->getId()], $ids);

        // The scheduled sweep must credit discovered payments, not just record
        // them as "todo" — same automatic-credit decision as a real-time webhook.
        foreach ($fetchMessages as $message) {
            self::assertTrue($message->attemptAutomaticCredit);
        }
    }

    public function testSyncOptionDoesNotDispatchMessages(): void
    {
        $this->persistClient('client-a', active: true);

        $command = (new Application(self::$kernel))->find('app:helloasso:fetch');
        (new CommandTester($command))->execute(['--sync' => true]);

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $fetchMessages = array_filter($transport->getSent(), static fn ($e) => $e->getMessage() instanceof FetchClientPaymentsMessage);
        self::assertCount(0, $fetchMessages);
    }
}
