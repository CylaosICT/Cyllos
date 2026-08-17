<?php

namespace App\Entity;

use App\Repository\HelloAssoConfigRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: HelloAssoConfigRepository::class)]
class HelloAssoConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'helloAssoConfig', targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    private ?Client $client = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $apiUrl = 'https://api.helloasso.com/';

    /**
     * HelloAsso's OAuth2 client_id credential (not to be confused with our Client entity relation).
     */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $helloAssoClientId = '';

    /**
     * Stored encrypted at rest, see SecretEncryptor.
     */
    #[ORM\Column(type: 'text')]
    private string $clientSecretEncrypted = '';

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $organizationSlug = '';

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $formSlug = '';

    /**
     * HelloAsso form type (CrowdFunding, PaymentForm, Membership, Event,
     * Donation, Shop...), used to build the payment history fetch URL. Must
     * match the actual campaign type in HelloAsso, or the catch-up fetch
     * (/v5/organizations/{org}/forms/{formType}/{formSlug}/payments) will
     * silently return no results.
     */
    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    private string $formType = 'PaymentForm';

    #[ORM\Column]
    #[Assert\Positive]
    private int $maxAmount = 250;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $extraMailFieldName = null;

    #[ORM\Column]
    #[Assert\Positive]
    private int $fetchNbDays = 5;

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

    public function getApiUrl(): string
    {
        return $this->apiUrl;
    }

    public function setApiUrl(string $apiUrl): static
    {
        $this->apiUrl = rtrim($apiUrl, '/') . '/';

        return $this;
    }

    public function getHelloAssoClientId(): string
    {
        return $this->helloAssoClientId;
    }

    public function setHelloAssoClientId(string $helloAssoClientId): static
    {
        $this->helloAssoClientId = $helloAssoClientId;

        return $this;
    }

    public function getClientSecretEncrypted(): string
    {
        return $this->clientSecretEncrypted;
    }

    public function setClientSecretEncrypted(string $clientSecretEncrypted): static
    {
        $this->clientSecretEncrypted = $clientSecretEncrypted;

        return $this;
    }

    public function getOrganizationSlug(): string
    {
        return $this->organizationSlug;
    }

    public function setOrganizationSlug(string $organizationSlug): static
    {
        $this->organizationSlug = $organizationSlug;

        return $this;
    }

    public function getFormSlug(): string
    {
        return $this->formSlug;
    }

    public function setFormSlug(string $formSlug): static
    {
        $this->formSlug = $formSlug;

        return $this;
    }

    public function getFormType(): string
    {
        return $this->formType;
    }

    public function setFormType(string $formType): static
    {
        $this->formType = $formType;

        return $this;
    }

    public function getMaxAmount(): int
    {
        return $this->maxAmount;
    }

    public function setMaxAmount(int $maxAmount): static
    {
        $this->maxAmount = $maxAmount;

        return $this;
    }

    public function getExtraMailFieldName(): ?string
    {
        return $this->extraMailFieldName;
    }

    public function setExtraMailFieldName(?string $extraMailFieldName): static
    {
        $this->extraMailFieldName = $extraMailFieldName;

        return $this;
    }

    public function getFetchNbDays(): int
    {
        return $this->fetchNbDays;
    }

    public function setFetchNbDays(int $fetchNbDays): static
    {
        $this->fetchNbDays = $fetchNbDays;

        return $this;
    }
}
