<?php

namespace App\Deployment;

/**
 * Result of comparing the locally running commit against the upstream company
 * repository's branch, as returned by VersionChecker.
 */
final class RepositoryStatus
{
    public function __construct(
        public readonly string $state,
        public readonly ?string $localCommit,
        public readonly ?string $remoteCommit,
        public readonly ?\DateTimeImmutable $remoteCommitDate,
        public readonly int $behindBy,
        public readonly int $aheadBy,
        public readonly \DateTimeImmutable $checkedAt,
        public readonly ?string $compareUrl,
        public readonly ?string $errorMessage,
        public readonly string $upstreamRepo,
        public readonly string $upstreamBranch,
    ) {
    }

    public const STATE_UP_TO_DATE = 'up_to_date';
    public const STATE_BEHIND = 'behind';
    public const STATE_AHEAD = 'ahead';
    public const STATE_DIVERGED = 'diverged';
    public const STATE_UNKNOWN = 'unknown';

    public function localShort(): ?string
    {
        return $this->localCommit !== null ? substr($this->localCommit, 0, 7) : null;
    }

    public function remoteShort(): ?string
    {
        return $this->remoteCommit !== null ? substr($this->remoteCommit, 0, 7) : null;
    }
}
