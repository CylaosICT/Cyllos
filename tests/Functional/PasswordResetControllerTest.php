<?php

namespace App\Tests\Functional;

use App\Entity\Client;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Self-service password reset is client-accounts-only, must not leak which
 * emails exist, and its tokens are single-use, time-limited and stored hashed.
 */
class PasswordResetControllerTest extends WebTestCase
{
    private KernelBrowser $httpClient;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->httpClient = static::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\ClientSetting')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\CyclosConfig')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\CheckoutOrder')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\StripeConfig')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\HelloAssoConfig')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Client')->execute();
    }

    /** @param list<string> $roles */
    private function persistUser(string $email, array $roles, ?Client $client): User
    {
        $user = (new User())->setEmail($email)->setPassword('unused')->setRoles($roles);
        if ($client !== null) {
            $user->setClient($client);
        }
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function persistClient(): Client
    {
        $client = (new Client())->setName('C')->setSlug('c-' . uniqid())->setActive(true);
        $this->entityManager->persist($client);
        $this->entityManager->flush();

        return $client;
    }

    private function submitForgotForm(string $email): void
    {
        $crawler = $this->httpClient->request('GET', '/mot-de-passe-oublie');
        $this->httpClient->submit($crawler->selectButton('Envoyer')->form(['forgot_password[email]' => $email]));
    }

    public function testForgotPasswordDoesNothingForAnAdminAccount(): void
    {
        $admin = $this->persistUser('admin@e.test', ['ROLE_ADMIN'], client: null);

        $this->submitForgotForm('admin@e.test');
        self::assertResponseRedirects('/login');

        $this->entityManager->clear();
        $reloaded = self::getContainer()->get(EntityManagerInterface::class)->getRepository(User::class)->find($admin->getId());
        self::assertNull($reloaded->getResetTokenHash(), 'no reset token must be issued for an internal account');
    }

    public function testForgotPasswordDoesNotRevealWhetherAnEmailExists(): void
    {
        $this->submitForgotForm('nobody@e.test');

        self::assertResponseRedirects('/login');
        $this->httpClient->followRedirect();
        self::assertStringContainsString('Si un compte client existe', $this->httpClient->getResponse()->getContent());
    }

    public function testForgotPasswordIssuesAHashedTimeLimitedTokenForAClientAccount(): void
    {
        $user = $this->persistUser('client@e.test', ['ROLE_CLIENT'], $this->persistClient());

        $this->submitForgotForm('client@e.test');

        $this->entityManager->clear();
        $reloaded = self::getContainer()->get(EntityManagerInterface::class)->getRepository(User::class)->find($user->getId());
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $reloaded->getResetTokenHash());
        self::assertGreaterThan(new \DateTimeImmutable('+30 minutes'), $reloaded->getResetTokenExpiresAt());
    }

    public function testResetWithAValidTokenChangesThePasswordAndBurnsTheToken(): void
    {
        $user = $this->persistUser('c2@e.test', ['ROLE_CLIENT'], $this->persistClient());
        $rawToken = bin2hex(random_bytes(32));
        $user->setResetToken(hash('sha256', $rawToken), new \DateTimeImmutable('+1 hour'));
        $this->entityManager->flush();

        $crawler = $this->httpClient->request('GET', '/reinitialiser-mot-de-passe/' . $rawToken);
        $this->httpClient->submit($crawler->selectButton('Réinitialiser')->form([
            'reset_password[plainPassword][first]' => 'new-strong-password',
            'reset_password[plainPassword][second]' => 'new-strong-password',
        ]));

        self::assertResponseRedirects('/login');

        $this->entityManager->clear();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $reloaded = $em->getRepository(User::class)->find($user->getId());
        self::assertNull($reloaded->getResetTokenHash());
        self::assertTrue(self::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($reloaded, 'new-strong-password'));
    }

    public function testResetWithAnExpiredTokenIsRejected(): void
    {
        $user = $this->persistUser('c3@e.test', ['ROLE_CLIENT'], $this->persistClient());
        $rawToken = bin2hex(random_bytes(32));
        $user->setResetToken(hash('sha256', $rawToken), new \DateTimeImmutable('-1 minute'));
        $this->entityManager->flush();

        $this->httpClient->request('GET', '/reinitialiser-mot-de-passe/' . $rawToken);

        self::assertResponseRedirects('/mot-de-passe-oublie');
    }

    public function testResetWithAnUnknownTokenIsRejected(): void
    {
        $this->httpClient->request('GET', '/reinitialiser-mot-de-passe/' . bin2hex(random_bytes(32)));

        self::assertResponseRedirects('/mot-de-passe-oublie');
    }
}
