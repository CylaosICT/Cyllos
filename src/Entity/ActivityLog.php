<?php

namespace App\Entity;

use App\Repository\ActivityLogRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Append-only audit trail of mutating actions taken in Cyllos, visible only
 * to developers. Populated by ActivityLogSubscriber (entity changes) and
 * SecurityActivityLogSubscriber (login events).
 */
#[ORM\Entity(repositoryClass: ActivityLogRepository::class)]
#[ORM\Index(columns: ['created_at'])]
class ActivityLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * Denormalized: kept even if the acting user is later deleted.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $actorEmail = null;

    /**
     * Short machine-readable action, e.g. "client.created", "user.login_failed".
     */
    #[ORM\Column(length: 100)]
    private string $action = '';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $summary = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $context = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    /**
     * Present only for action = "api.*" rows: raw request/response trace of a
     * call to an external API (HelloAsso/Cyclos), for troubleshooting. Never
     * contains credentials — see ApiCallLogger for redaction rules.
     */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $apiService = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $apiMethod = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $apiUrl = null;

    #[ORM\Column(nullable: true)]
    private ?int $apiStatusCode = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $apiRequestBody = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $apiResponseBody = null;

    public function __construct()
    {
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

    public function getActorEmail(): ?string
    {
        return $this->actorEmail;
    }

    public function setActorEmail(?string $actorEmail): static
    {
        $this->actorEmail = $actorEmail;

        return $this;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function setAction(string $action): static
    {
        $this->action = $action;

        return $this;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function setSummary(?string $summary): static
    {
        $this->summary = $summary;

        return $this;
    }

    public function getContext(): ?string
    {
        return $this->context;
    }

    public function setContext(?string $context): static
    {
        $this->context = $context;

        return $this;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): static
    {
        $this->ipAddress = $ipAddress;

        return $this;
    }

    public function isApiCall(): bool
    {
        return $this->apiService !== null;
    }

    public function getApiService(): ?string
    {
        return $this->apiService;
    }

    public function setApiService(?string $apiService): static
    {
        $this->apiService = $apiService;

        return $this;
    }

    public function getApiMethod(): ?string
    {
        return $this->apiMethod;
    }

    public function setApiMethod(?string $apiMethod): static
    {
        $this->apiMethod = $apiMethod;

        return $this;
    }

    public function getApiUrl(): ?string
    {
        return $this->apiUrl;
    }

    public function setApiUrl(?string $apiUrl): static
    {
        $this->apiUrl = $apiUrl;

        return $this;
    }

    public function getApiStatusCode(): ?int
    {
        return $this->apiStatusCode;
    }

    public function setApiStatusCode(?int $apiStatusCode): static
    {
        $this->apiStatusCode = $apiStatusCode;

        return $this;
    }

    public function getApiRequestBody(): ?string
    {
        return $this->apiRequestBody;
    }

    public function setApiRequestBody(?string $apiRequestBody): static
    {
        $this->apiRequestBody = $apiRequestBody;

        return $this;
    }

    public function getApiResponseBody(): ?string
    {
        return $this->apiResponseBody;
    }

    public function setApiResponseBody(?string $apiResponseBody): static
    {
        $this->apiResponseBody = $apiResponseBody;

        return $this;
    }
}
