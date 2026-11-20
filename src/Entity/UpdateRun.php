<?php

namespace App\Entity;

use App\Repository\UpdateRunRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One deployment of Cyllos (the back-office) or Cyllos-Pay: who launched it,
 * the commits it brought, its outcome and the safety backups taken first. The
 * live terminal output is kept in var/updates/run-{id}.log.
 */
#[ORM\Entity(repositoryClass: UpdateRunRepository::class)]
#[ORM\Index(columns: ['started_at'])]
class UpdateRun
{
    public const TARGET_APP = 'app';
    public const TARGET_PAY = 'pay';

    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ROLLED_BACK = 'rolled_back';
    public const STATUS_ROLLBACK_FAILED = 'rollback_failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_RUNNING;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $fromCommit = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $toCommit = null;

    /**
     * @var list<array{sha: string, author: string, subject: string}>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $commits = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?DatabaseBackup $databaseBackup = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $codeBackupFile = null;

    #[ORM\Column(nullable: true)]
    private ?int $codeBackupSize = null;

    public function __construct(
        #[ORM\Column(length: 10)]
        private string $target,
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $triggeredBy = null,
    ) {
        $this->startedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTarget(): string
    {
        return $this->target;
    }

    public function targetLabel(): string
    {
        return $this->target === self::TARGET_PAY ? 'Cyllos-Pay' : 'Cyllos (back-office)';
    }

    public function getTriggeredBy(): ?string
    {
        return $this->triggeredBy;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    public function finish(string $status): static
    {
        $this->status = $status;
        $this->finishedAt = new \DateTimeImmutable();

        return $this;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_RUNNING => 'En cours',
            self::STATUS_SUCCESS => 'Réussie',
            self::STATUS_FAILED => 'Échec',
            self::STATUS_ROLLED_BACK => 'Échec — version précédente restaurée',
            self::STATUS_ROLLBACK_FAILED => 'Échec — restauration impossible',
            default => $this->status,
        };
    }

    public function statusBadge(): string
    {
        return match ($this->status) {
            self::STATUS_RUNNING => 'badge--waiting',
            self::STATUS_SUCCESS => 'badge--success',
            self::STATUS_ROLLED_BACK => 'badge--warning',
            default => 'badge--fail',
        };
    }

    public function getDurationSeconds(): ?int
    {
        return $this->finishedAt === null ? null : $this->finishedAt->getTimestamp() - $this->startedAt->getTimestamp();
    }

    public function getFromCommit(): ?string
    {
        return $this->fromCommit;
    }

    public function setFromCommit(?string $fromCommit): static
    {
        $this->fromCommit = $fromCommit;

        return $this;
    }

    public function getToCommit(): ?string
    {
        return $this->toCommit;
    }

    public function setToCommit(?string $toCommit): static
    {
        $this->toCommit = $toCommit;

        return $this;
    }

    /**
     * @return list<array{sha: string, author: string, subject: string}>
     */
    public function getCommits(): array
    {
        return $this->commits ?? [];
    }

    /**
     * @param list<array{sha: string, author: string, subject: string}> $commits
     */
    public function setCommits(array $commits): static
    {
        $this->commits = $commits;

        return $this;
    }

    public function getDatabaseBackup(): ?DatabaseBackup
    {
        return $this->databaseBackup;
    }

    public function setDatabaseBackup(?DatabaseBackup $databaseBackup): static
    {
        $this->databaseBackup = $databaseBackup;

        return $this;
    }

    public function getCodeBackupFile(): ?string
    {
        return $this->codeBackupFile;
    }

    public function getCodeBackupSize(): ?int
    {
        return $this->codeBackupSize;
    }

    public function setCodeBackup(?string $file, ?int $size): static
    {
        $this->codeBackupFile = $file;
        $this->codeBackupSize = $size;

        return $this;
    }
}
