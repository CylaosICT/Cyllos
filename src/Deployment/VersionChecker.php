<?php

namespace App\Deployment;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessExceptionInterface;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Compares the commit currently running against the HEAD of the company's
 * canonical GitHub repository, so a fork/deployment can tell it has drifted
 * from upstream. Read-only: it never pulls or writes anything.
 */
class VersionChecker
{
    private const CACHE_TTL_SECONDS = 300;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheItemPoolInterface $cache,
        private readonly string $projectDir,
        private readonly string $upstreamRepo,
        private readonly string $upstreamBranch,
        private readonly ?string $githubToken,
        private readonly ?string $commitShaOverride,
    ) {
    }

    public function check(bool $forceRefresh = false): RepositoryStatus
    {
        $localCommit = $this->resolveLocalCommit();
        $cacheKey = 'version_check.' . hash('crc32', $this->upstreamRepo . '|' . $this->upstreamBranch . '|' . ($localCommit ?? 'none'));

        $item = $this->cache->getItem($cacheKey);
        if (!$forceRefresh && $item->isHit()) {
            return $item->get();
        }

        $status = $this->fetchStatus($localCommit);

        $item->set($status);
        $item->expiresAfter(self::CACHE_TTL_SECONDS);
        $this->cache->save($item);

        return $status;
    }

    private function resolveLocalCommit(): ?string
    {
        if ($this->commitShaOverride !== null && $this->commitShaOverride !== '') {
            return $this->commitShaOverride;
        }

        try {
            $process = new Process(['git', 'rev-parse', 'HEAD'], $this->projectDir);
            $process->run();

            if (!$process->isSuccessful()) {
                return null;
            }

            return trim($process->getOutput()) ?: null;
        } catch (ProcessExceptionInterface) {
            return null;
        }
    }

    private function fetchStatus(?string $localCommit): RepositoryStatus
    {
        $now = new \DateTimeImmutable();

        if ($localCommit === null) {
            return new RepositoryStatus(
                state: RepositoryStatus::STATE_UNKNOWN,
                localCommit: null,
                remoteCommit: null,
                remoteCommitDate: null,
                behindBy: 0,
                aheadBy: 0,
                checkedAt: $now,
                compareUrl: null,
                errorMessage: "Commit local introuvable (pas de dépôt git déployé et APP_COMMIT_SHA non défini).",
                upstreamRepo: $this->upstreamRepo,
                upstreamBranch: $this->upstreamBranch,
            );
        }

        try {
            $response = $this->httpClient->request(
                'GET',
                sprintf('https://api.github.com/repos/%s/compare/%s...%s', $this->upstreamRepo, $localCommit, $this->upstreamBranch),
                ['headers' => $this->headers()],
            );

            $statusCode = $response->getStatusCode();

            if ($statusCode === 404) {
                return $this->unavailable($localCommit, $now, "Dépôt \"{$this->upstreamRepo}\" introuvable ou inaccessible — vérifie GITHUB_UPSTREAM_REPO et, s'il est privé, GITHUB_TOKEN.");
            }

            if ($statusCode === 401 || $statusCode === 403) {
                return $this->unavailable($localCommit, $now, 'Accès refusé par GitHub — GITHUB_TOKEN manquant, invalide, ou sans droit de lecture sur ce dépôt.');
            }

            if ($statusCode >= 300) {
                return $this->unavailable($localCommit, $now, sprintf('GitHub a répondu avec le code %d.', $statusCode));
            }

            $data = $response->toArray(false);

            $remoteCommit = $data['commits'][array_key_last($data['commits'] ?? [])]['sha'] ?? $data['merge_base_commit']['sha'] ?? null;
            $remoteDateRaw = $data['commits'][array_key_last($data['commits'] ?? [])]['commit']['committer']['date'] ?? null;

            $ghStatus = $data['status'] ?? 'diverged';
            $state = match ($ghStatus) {
                'identical' => RepositoryStatus::STATE_UP_TO_DATE,
                'behind' => RepositoryStatus::STATE_BEHIND,
                'ahead' => RepositoryStatus::STATE_AHEAD,
                default => RepositoryStatus::STATE_DIVERGED,
            };

            return new RepositoryStatus(
                state: $state,
                localCommit: $localCommit,
                remoteCommit: $remoteCommit ?? $localCommit,
                remoteCommitDate: $remoteDateRaw !== null ? new \DateTimeImmutable($remoteDateRaw) : null,
                behindBy: (int) ($data['behind_by'] ?? 0),
                aheadBy: (int) ($data['ahead_by'] ?? 0),
                checkedAt: $now,
                compareUrl: $data['html_url'] ?? sprintf('https://github.com/%s/compare/%s...%s', $this->upstreamRepo, $localCommit, $this->upstreamBranch),
                errorMessage: null,
                upstreamRepo: $this->upstreamRepo,
                upstreamBranch: $this->upstreamBranch,
            );
        } catch (HttpClientExceptionInterface|\Throwable $exception) {
            return $this->unavailable($localCommit, $now, 'Impossible de contacter GitHub : ' . $exception->getMessage());
        }
    }

    private function unavailable(?string $localCommit, \DateTimeImmutable $now, string $message): RepositoryStatus
    {
        return new RepositoryStatus(
            state: RepositoryStatus::STATE_UNKNOWN,
            localCommit: $localCommit,
            remoteCommit: null,
            remoteCommitDate: null,
            behindBy: 0,
            aheadBy: 0,
            checkedAt: $now,
            compareUrl: null,
            errorMessage: $message,
            upstreamRepo: $this->upstreamRepo,
            upstreamBranch: $this->upstreamBranch,
        );
    }

    private function headers(): array
    {
        $headers = [
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'Cyllos-VersionChecker',
        ];

        if ($this->githubToken !== null && $this->githubToken !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->githubToken;
        }

        return $headers;
    }
}
