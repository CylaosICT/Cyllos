<?php

namespace App\Entity;

use App\Repository\CyclosConfigRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: CyclosConfigRepository::class)]
class CyclosConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'cyclosConfig', targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    private ?Client $client = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $baseUrl = '';

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    private string $technicalUserId = '';

    /**
     * Stored encrypted at rest, see SecretEncryptor.
     */
    #[ORM\Column(type: 'text')]
    private string $passwordEncrypted = '';

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $groupProInternal = '';

    /**
     * Comma-separated list of internal group names considered "particulier".
     */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $groupsPartInternal = '';

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $emissionProInternal = '';

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $emissionPartInternal = '';

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

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function setBaseUrl(string $baseUrl): static
    {
        $this->baseUrl = rtrim($baseUrl, '/') . '/';

        return $this;
    }

    public function getTechnicalUserId(): string
    {
        return $this->technicalUserId;
    }

    public function setTechnicalUserId(string $technicalUserId): static
    {
        $this->technicalUserId = $technicalUserId;

        return $this;
    }

    public function getPasswordEncrypted(): string
    {
        return $this->passwordEncrypted;
    }

    public function setPasswordEncrypted(string $passwordEncrypted): static
    {
        $this->passwordEncrypted = $passwordEncrypted;

        return $this;
    }

    public function getGroupProInternal(): string
    {
        return $this->groupProInternal;
    }

    public function setGroupProInternal(string $groupProInternal): static
    {
        $this->groupProInternal = $groupProInternal;

        return $this;
    }

    public function getGroupsPartInternal(): string
    {
        return $this->groupsPartInternal;
    }

    public function setGroupsPartInternal(string $groupsPartInternal): static
    {
        $this->groupsPartInternal = $groupsPartInternal;

        return $this;
    }

    /**
     * @return string[]
     */
    public function getGroupsPartInternalList(): array
    {
        return array_filter(array_map('trim', explode(',', $this->groupsPartInternal)));
    }

    public function getEmissionProInternal(): string
    {
        return $this->emissionProInternal;
    }

    public function setEmissionProInternal(string $emissionProInternal): static
    {
        $this->emissionProInternal = $emissionProInternal;

        return $this;
    }

    public function getEmissionPartInternal(): string
    {
        return $this->emissionPartInternal;
    }

    public function setEmissionPartInternal(string $emissionPartInternal): static
    {
        $this->emissionPartInternal = $emissionPartInternal;

        return $this;
    }
}
