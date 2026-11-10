<?php

namespace App\Tests\Unit\Integration\Cyclos;

use App\ActivityLog\ApiCallLogger;
use App\Entity\CyclosConfig;
use App\Integration\Cyclos\CyclosClient;
use App\Security\SecretEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Regression test for a real double-credit incident: hasAlreadyCreditedPayment()
 * used to check only the single most recent transaction, so a payment's credit
 * stopped being detected as soon as anything else was credited to the same
 * account afterward — pushing it out of "the last one". The fixture below is
 * the actual shape returned by a real Cyclos test instance while investigating
 * the bug (see CyclosClient::DUPLICATE_CHECK_WINDOW).
 */
class CyclosClientHasAlreadyCreditedPaymentTest extends TestCase
{
    private SecretEncryptor $encryptor;

    protected function setUp(): void
    {
        $this->encryptor = new SecretEncryptor(base64_encode(str_repeat('a', 32)));
    }

    private function makeClient(MockHttpClient $httpClient): CyclosClient
    {
        return new CyclosClient(
            $httpClient,
            $this->encryptor,
            new NullLogger(),
            new ApiCallLogger($this->createStub(EntityManagerInterface::class)),
        );
    }

    private function makeConfig(): CyclosConfig
    {
        return (new CyclosConfig())
            ->setBaseUrl('https://cyclos.example/api/')
            ->setTechnicalUserId('1')
            ->setPasswordEncrypted($this->encryptor->encrypt('pwd'));
    }

    public function testDetectsAMatchThatIsNotTheMostRecentTransaction(): void
    {
        $httpClient = new MockHttpClient(new MockResponse(json_encode([
            ['description' => null],
            ['description' => 'Recharge instantanée N° 65456'],
            ['description' => 'Cotisation CAF'],
        ])));

        $client = $this->makeClient($httpClient);

        self::assertTrue($client->hasAlreadyCreditedPayment(
            $this->makeConfig(),
            'eric.debelair@gmail.com',
            ['Recharge instantanée N° 65456'],
        ));
    }

    public function testReturnsFalseWhenNoTransactionMatches(): void
    {
        $httpClient = new MockHttpClient(new MockResponse(json_encode([
            ['description' => 'Cotisation CAF'],
        ])));

        $client = $this->makeClient($httpClient);

        self::assertFalse($client->hasAlreadyCreditedPayment(
            $this->makeConfig(),
            'eric.debelair@gmail.com',
            ['Recharge instantanée N° 65456'],
        ));
    }

    public function testMatchesAnyOfTheCandidateDescriptions(): void
    {
        // Client switched its Cyclos description prefix: the earlier credit is
        // on file under the legacy wording, the "current" candidate is the new
        // one — passing both must still detect the duplicate.
        $httpClient = new MockHttpClient(new MockResponse(json_encode([
            ['description' => 'Paiement automatique, id technique 65456'],
        ])));

        $client = $this->makeClient($httpClient);

        self::assertTrue($client->hasAlreadyCreditedPayment(
            $this->makeConfig(),
            'eric.debelair@gmail.com',
            ['Recharge instantanée N° 65456', 'Paiement automatique, id technique 65456'],
        ));
    }

    public function testLegacyDefaultPrefixesAreDeclared(): void
    {
        // The pre-rename default must stay recognised by the duplicate check.
        self::assertContains('Paiement automatique, id technique ', CyclosClient::LEGACY_PAYMENT_DESCRIPTION_PREFIXES);
    }

    public function testReturnsFalseWhenTheAccountHasNoTransactions(): void
    {
        $httpClient = new MockHttpClient(new MockResponse('[]'));

        $client = $this->makeClient($httpClient);

        self::assertFalse($client->hasAlreadyCreditedPayment(
            $this->makeConfig(),
            'eric.debelair@gmail.com',
            ['Recharge instantanée N° 65456'],
        ));
    }
}
