<?php

namespace App\Entity;

use App\Repository\ClientRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ClientRepository::class)]
#[UniqueEntity(fields: ['slug'], message: 'Ce slug est déjà utilisé par un autre client.')]
class Client
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $name = '';

    #[ORM\Column(length: 100, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-z0-9-]+$/', message: 'Le slug ne doit contenir que des minuscules, chiffres et tirets.')]
    private string $slug = '';

    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * Stored filename of the client's logo in public/uploads/client-logos/, if any.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logoFilename = null;

    #[ORM\OneToOne(mappedBy: 'client', targetEntity: HelloAssoConfig::class, cascade: ['persist', 'remove'])]
    private ?HelloAssoConfig $helloAssoConfig = null;

    #[ORM\OneToOne(mappedBy: 'client', targetEntity: CyclosConfig::class, cascade: ['persist', 'remove'])]
    private ?CyclosConfig $cyclosConfig = null;

    #[ORM\OneToOne(mappedBy: 'client', targetEntity: ClientSetting::class, cascade: ['persist', 'remove'])]
    private ?ClientSetting $setting = null;

    #[ORM\OneToMany(mappedBy: 'client', targetEntity: Payment::class)]
    private Collection $payments;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->payments = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLogoFilename(): ?string
    {
        return $this->logoFilename;
    }

    public function setLogoFilename(?string $logoFilename): static
    {
        $this->logoFilename = $logoFilename;

        return $this;
    }

    public function getHelloAssoConfig(): ?HelloAssoConfig
    {
        return $this->helloAssoConfig;
    }

    public function setHelloAssoConfig(?HelloAssoConfig $helloAssoConfig): static
    {
        $this->helloAssoConfig = $helloAssoConfig;
        if ($helloAssoConfig !== null && $helloAssoConfig->getClient() !== $this) {
            $helloAssoConfig->setClient($this);
        }

        return $this;
    }

    public function getCyclosConfig(): ?CyclosConfig
    {
        return $this->cyclosConfig;
    }

    public function setCyclosConfig(?CyclosConfig $cyclosConfig): static
    {
        $this->cyclosConfig = $cyclosConfig;
        if ($cyclosConfig !== null && $cyclosConfig->getClient() !== $this) {
            $cyclosConfig->setClient($this);
        }

        return $this;
    }

    public function getSetting(): ?ClientSetting
    {
        return $this->setting;
    }

    public function setSetting(?ClientSetting $setting): static
    {
        $this->setting = $setting;
        if ($setting !== null && $setting->getClient() !== $this) {
            $setting->setClient($this);
        }

        return $this;
    }

    /**
     * @return Collection<int, Payment>
     */
    public function getPayments(): Collection
    {
        return $this->payments;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
