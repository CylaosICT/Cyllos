<?php

namespace App\Tests\Unit\Deployment;

use App\Deployment\PaySiteStatus;
use App\Deployment\PaySiteUpdater;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class PaySiteUpdaterTest extends TestCase
{
    private const SECRET = 'shared-secret-0123456789abcdef0123456789abcdef';

    /**
     * @param list<MockResponse> $responses
     */
    private function updater(array $responses, string $secret = self::SECRET): PaySiteUpdater
    {
        return new PaySiteUpdater(new MockHttpClient($responses), 'https://pay.example/', $secret);
    }

    public function testStatusReadsTheRemoteStateAndSignsTheRequest(): void
    {
        $captured = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = ['method' => $method, 'url' => $url, 'headers' => $options['normalized_headers'], 'body' => $options['body']];

            return new MockResponse(json_encode(['commit' => 'aaaaaaa1', 'remoteCommit' => 'bbbbbbb2', 'remoteDate' => '2026-11-16T10:00:00+00:00', 'behind' => 3, 'ahead' => 0, 'state' => 'ok', 'running' => false, 'log' => 'x', 'error' => null, 'branch' => 'main']));
        });

        $status = (new PaySiteUpdater($client, 'https://pay.example/', self::SECRET))->status();

        self::assertSame(PaySiteStatus::STATE_BEHIND, $status->state);
        self::assertSame(3, $status->behindBy);
        self::assertSame('aaaaaaa', $status->localShort());
        self::assertSame('POST', $captured['method']);
        self::assertSame('https://pay.example/internal/update/status', $captured['url']);
        $timestamp = $captured['headers']['x-cyllos-timestamp'][0] ?? '';
        $expected = hash_hmac('sha256', substr($timestamp, \strlen('X-Cyllos-Timestamp: ')) . '.' . $captured['body'], self::SECRET);
        self::assertSame('X-Cyllos-Signature: ' . $expected, $captured['headers']['x-cyllos-signature'][0]);
    }

    public function testUpToDateWhenNothingIsBehind(): void
    {
        $status = $this->updater([new MockResponse(json_encode(['commit' => 'a', 'remoteCommit' => 'a', 'behind' => 0, 'ahead' => 0]))])->status();

        self::assertSame(PaySiteStatus::STATE_UP_TO_DATE, $status->state);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function failures(): iterable
    {
        yield 'bad signature' => [401, 'signature'];
        yield 'old payment site' => [404, 'pas encore la fonction'];
        yield 'server error' => [500, 'code 500'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function testFailuresBecomeAReadableUnknownStatus(int $code, string $expected): void
    {
        $status = $this->updater([new MockResponse('', ['http_code' => $code])])->status();

        self::assertSame(PaySiteStatus::STATE_UNKNOWN, $status->state);
        self::assertStringContainsString($expected, (string) $status->errorMessage);
    }

    public function testDeployReportsWhetherItStarted(): void
    {
        self::assertTrue($this->updater([new MockResponse('{"started":true}')])->deploy());
        self::assertFalse($this->updater([new MockResponse('{"started":false}')])->deploy());
    }

    public function testNotConfiguredWithoutSecret(): void
    {
        $updater = $this->updater([], '');

        self::assertFalse($updater->isConfigured());
        self::assertSame(PaySiteStatus::STATE_UNKNOWN, $updater->status()->state);
    }
}
