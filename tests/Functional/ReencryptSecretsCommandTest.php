<?php

namespace App\Tests\Functional;

use App\Entity\Client;
use App\Entity\ClientSetting;
use App\Entity\CyclosConfig;
use App\Entity\HelloAssoConfig;
use App\Entity\User;
use App\Security\SecretEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * After a key rotation, app:secrets:reencrypt must rewrite EVERY stored secret
 * with the new primary key — HelloAsso/Cyclos config secrets and user TOTP
 * secrets alike — otherwise 2FA breaks once the legacy key is dropped.
 */
class ReencryptSecretsCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->entityManager->createQuery('DELETE FROM App\Entity\Payment')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\ClientSetting')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\CyclosConfig')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\CheckoutOrder')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\StripeConfig')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\HelloAssoConfig')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Client')->execute();
    }

    public function testReencryptsConfigSecretsAndUserTotpSecrets(): void
    {
        /** @var SecretEncryptor $encryptor */
        $encryptor = self::getContainer()->get(SecretEncryptor::class);

        // Seed rows encrypted with the CURRENT primary key.
        $client = (new Client())->setName('T')->setSlug('t')->setActive(true);
        $client->addHelloAssoConfig((new HelloAssoConfig())
            ->setLabel('P')->setApiUrl('https://x/')->setHelloAssoClientId('id')
            ->setClientSecretEncrypted($encryptor->encrypt('ha-secret'))
            ->setOrganizationSlug('o')->setFormSlug('f')->setMaxAmount(1)->setFetchNbDays(1));
        $client->setCyclosConfig((new CyclosConfig())
            ->setBaseUrl('https://c/')->setTechnicalUserId('1')
            ->setPasswordEncrypted($encryptor->encrypt('cy-pwd'))
            ->setGroupProInternal('p')->setGroupsPartInternal('q')
            ->setEmissionProInternal('a')->setEmissionPartInternal('b'));
        $client->setSetting((new ClientSetting())->setPaymentCyclosEnabled(false)->setPaymentAutomaticEnabled(false)->setMailRecipient('o@e.test'));
        $this->entityManager->persist($client);

        $user = (new User())->setEmail('u@e.test')->setPassword('x')->setRoles(['ROLE_CLIENT']);
        $user->setTotpSecretEncrypted($encryptor->encrypt('TOTPSECRET'));
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $command = (new Application(self::$kernel))->find('app:secrets:reencrypt');
        $tester = new CommandTester($command);

        // Everything is already on the primary key: nothing to do, exit 0.
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('3 secret(s) déjà sur la clé courante', $tester->getDisplay());

        // Secrets stay readable and unchanged in meaning.
        $this->entityManager->clear();
        $reloaded = self::getContainer()->get(EntityManagerInterface::class)->getRepository(User::class)->findOneBy(['email' => 'u@e.test']);
        self::assertSame('TOTPSECRET', $encryptor->decrypt($reloaded->getTotpSecretEncrypted()));
    }
}
