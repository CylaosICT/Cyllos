<?php

namespace App\Twig;

use App\Deployment\RepositoryStatus;
use App\Deployment\VersionChecker;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the cached upstream-vs-local repository status to templates, so the
 * sidebar can flag a stale deployment without every page paying for a live
 * GitHub call (VersionChecker::check() reads from cache unless it's expired).
 */
class VersionStatusExtension extends AbstractExtension
{
    public function __construct(
        private readonly VersionChecker $versionChecker,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('app_version_status', $this->getStatus(...)),
        ];
    }

    public function getStatus(): RepositoryStatus
    {
        return $this->versionChecker->check();
    }
}
