<?php

namespace App\Tests\Unit\Integration\Cyclos;

use App\ActivityLog\ApiCallLogger;
use App\Entity\CyclosConfig;
use App\Integration\Cyclos\CyclosClient;
use App\Integration\Cyclos\CyclosUser;
use App\Security\SecretEncryptor;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;

class CyclosClientResolveEmissionTypeTest extends TestCase
{
    private CyclosClient $client;
    private CyclosConfig $config;

    protected function setUp(): void
    {
        $this->client = new CyclosClient(
            new MockHttpClient(),
            new SecretEncryptor(base64_encode(str_repeat('a', 32))),
            new NullLogger(),
            new ApiCallLogger($this->createStub(EntityManagerInterface::class)),
        );

        $this->config = (new CyclosConfig())
            ->setGroupProInternal('pro')
            ->setGroupsPartInternal('part,autre-part')
            ->setEmissionProInternal('emission.CreditPro')
            ->setEmissionPartInternal('emission.CreditParticulier');
    }

    public function testResolvesProfessionalEmission(): void
    {
        $user = new CyclosUser('42', 'pro');

        self::assertSame('emission.CreditPro', $this->client->resolveEmissionType($this->config, $user));
    }

    public function testResolvesParticulierEmissionFromAnyConfiguredGroup(): void
    {
        self::assertSame('emission.CreditParticulier', $this->client->resolveEmissionType($this->config, new CyclosUser('1', 'part')));
        self::assertSame('emission.CreditParticulier', $this->client->resolveEmissionType($this->config, new CyclosUser('2', 'autre-part')));
    }

    public function testReturnsNullForUnauthorizedGroup(): void
    {
        $user = new CyclosUser('99', 'some-other-group');

        self::assertNull($this->client->resolveEmissionType($this->config, $user));
    }

    public function testReturnsNullWhenUserHasNoGroup(): void
    {
        $user = new CyclosUser('99', null);

        self::assertNull($this->client->resolveEmissionType($this->config, $user));
    }
}
