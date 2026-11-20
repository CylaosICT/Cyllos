<?php

namespace App\Entity;

use App\Repository\DatabaseBackupRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A gzipped SQL dump of the Cyllos database, stored under var/backups/db.
 */
#[ORM\Entity(repositoryClass: DatabaseBackupRepository::class)]
#[ORM\Index(columns: ['created_at'])]
class DatabaseBackup
{
    public const TYPE_MANUAL = 'manual';
    public const TYPE_AUTO = 'auto';
    public const TYPE_PRE_UPDATE = 'pre_update';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\Column(length: 255)]
        private string $filename,
        #[ORM\Column(length: 20)]
        private string $type,
        #[ORM\Column]
        private int $sizeBytes,
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $createdBy = null,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_AUTO => 'Automatique',
            self::TYPE_PRE_UPDATE => 'Avant mise à jour',
            default => 'Manuelle',
        };
    }

    public function getSizeBytes(): int
    {
        return $this->sizeBytes;
    }

    public function getCreatedBy(): ?string
    {
        return $this->createdBy;
    }
}
