<?php

namespace App\Deployment;

/**
 * What the payment site (cyllos-pay) reports about its own version and update,
 * or why it could not be reached.
 */
final class PaySiteStatus
{
    public const STATE_UP_TO_DATE = 'up_to_date';
    public const STATE_BEHIND = 'behind';
    public const STATE_AHEAD = 'ahead';
    public const STATE_UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $state,
        public readonly ?string $localCommit = null,
        public readonly ?string $remoteCommit = null,
        public readonly ?\DateTimeImmutable $remoteCommitDate = null,
        public readonly int $behindBy = 0,
        public readonly int $aheadBy = 0,
        public readonly bool $running = false,
        /** 'running' | 'ok' | 'failed' | 'never' */
        public readonly string $lastRun = 'never',
        public readonly string $log = '',
        public readonly ?string $errorMessage = null,
        public readonly string $branch = 'main',
    ) {
    }

    public function localShort(): ?string
    {
        return $this->localCommit !== null ? substr($this->localCommit, 0, 7) : null;
    }

    public function remoteShort(): ?string
    {
        return $this->remoteCommit !== null ? substr($this->remoteCommit, 0, 7) : null;
    }
}
