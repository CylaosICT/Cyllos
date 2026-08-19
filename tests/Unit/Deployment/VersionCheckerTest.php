<?php

namespace App\Tests\Unit\Deployment;

use App\Deployment\RepositoryStatus;
use App\Deployment\VersionChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The GitHub compare API is called as compare/{localCommit}...{upstreamBranch}
 * (base=local, head=upstream), and its "status" always describes head relative
 * to base. Mapping "ahead"/"behind" literally (instead of inverted) would report
 * the opposite of reality — this is exactly the bug that hid the "Deploy update"
 * button in production while the deployed commit was actually 3 commits behind.
 */
class VersionCheckerTest extends TestCase
{
    private function makeChecker(MockHttpClient $httpClient): VersionChecker
    {
        return new VersionChecker(
            $httpClient,
            new ArrayAdapter(),
            __DIR__, // projectDir — irrelevant here since commitShaOverride bypasses git
            'CylaosICT/Cyllos',
            'main',
            null,
            'abc1234', // commitShaOverride: skip the git rev-parse lookup
        );
    }

    public function testGithubAheadStatusMeansLocalIsBehind(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse(json_encode([
                'status' => 'ahead',
                'ahead_by' => 3,
                'behind_by' => 0,
                'html_url' => 'https://github.com/CylaosICT/Cyllos/compare/abc1234...main',
                'commits' => [
                    ['sha' => 'def5678', 'commit' => ['committer' => ['date' => '2026-08-19T12:39:00Z']]],
                ],
            ]), ['http_code' => 200]),
        ]);

        $status = $this->makeChecker($httpClient)->check();

        self::assertSame(RepositoryStatus::STATE_BEHIND, $status->state);
        self::assertSame(3, $status->behindBy);
        self::assertSame(0, $status->aheadBy);
    }

    public function testGithubBehindStatusMeansLocalIsAhead(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse(json_encode([
                'status' => 'behind',
                'ahead_by' => 0,
                'behind_by' => 3,
                'html_url' => 'https://github.com/CylaosICT/Cyllos/compare/abc1234...main',
                'commits' => [],
            ]), ['http_code' => 200]),
        ]);

        $status = $this->makeChecker($httpClient)->check();

        self::assertSame(RepositoryStatus::STATE_AHEAD, $status->state);
        self::assertSame(0, $status->behindBy);
        self::assertSame(3, $status->aheadBy);
    }

    public function testGithubIdenticalStatusMeansUpToDate(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse(json_encode([
                'status' => 'identical',
                'ahead_by' => 0,
                'behind_by' => 0,
                'html_url' => 'https://github.com/CylaosICT/Cyllos/compare/abc1234...main',
                'commits' => [],
            ]), ['http_code' => 200]),
        ]);

        $status = $this->makeChecker($httpClient)->check();

        self::assertSame(RepositoryStatus::STATE_UP_TO_DATE, $status->state);
    }
}
