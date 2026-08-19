<?php

namespace App\Entity;

use App\Repository\EmailAliasRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A persistent per-client correction rule: when a HelloAsso payer's email
 * (sourceEmail) doesn't match their Cyclos account's email, this maps it to
 * the correct one (targetEmail). Applied automatically at the start of every
 * Cyclos credit attempt for this client — set once, applies to every future
 * payment from that payer without needing to fix it again each time.
 */
#[ORM\Entity(repositoryClass: EmailAliasRepository::class)]
#[ORM\UniqueConstraint(name: 'email_alias_client_source_unique', columns: ['client_id', 'source_email'])]
class EmailAlias
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Client $client = null;

    /**
     * The email HelloAsso reports for the payer — what actually comes in on
     * the payment, and would otherwise fail to resolve to a Cyclos account.
     */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private string $sourceEmail = '';

    /**
     * The email of the payer's real Cyclos account, used in place of
     * sourceEmail for every step of the credit (user lookup, anti-doublon
     * check, the payment itself).
     */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private string $targetEmail = '';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function setClient(?Client $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function getSourceEmail(): string
    {
        return $this->sourceEmail;
    }

    public function setSourceEmail(string $sourceEmail): static
    {
        $this->sourceEmail = strtolower(trim($sourceEmail));

        return $this;
    }

    public function getTargetEmail(): string
    {
        return $this->targetEmail;
    }

    public function setTargetEmail(string $targetEmail): static
    {
        $this->targetEmail = strtolower(trim($targetEmail));

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
