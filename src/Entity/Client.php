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
     * Shared secret embedded in the client's HelloAsso webhook URL
     * (/webhook/helloasso/{slug}/{webhookToken}). HelloAsso does not sign its
     * notifications, so this token is the only thing that authenticates an
     * incoming payload as really coming from this client's HelloAsso account.
     * 64 hex chars = 32 random bytes; generated on creation, rotatable via
     * regenerateWebhookToken().
     */
    #[ORM\Column(length: 64, unique: true)]
    private string $webhookToken;

    /**
     * Last time HelloAsso hit the token-less legacy webhook URL for this
     * client (see WebhookController::legacy()). A recent value means the
     * client's notification URL in HelloAsso still needs the token appended;
     * surfaced on the admin dashboard. Written at most hourly to keep the
     * unauthenticated legacy endpoint cheap.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $legacyWebhookLastSeenAt = null;

    /**
     * Stored filename of the client's logo in public/uploads/client-logos/, if any.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logoFilename = null;

    /**
     * Which payment service this client collects top-ups through. Chosen at
     * creation (wizard step 2). HelloAsso clients use helloAssoConfigs,
     * Stripe clients use stripeConfig.
     */
    #[ORM\Column(length: 20, enumType: PaymentProvider::class)]
    private PaymentProvider $paymentProvider = PaymentProvider::HelloAsso;

    #[ORM\OneToOne(mappedBy: 'client', targetEntity: StripeConfig::class, cascade: ['persist', 'remove'])]
    private ?StripeConfig $stripeConfig = null;

    /**
     * Ordered by id (creation order) rather than left to the database's
     * default — several call sites (the "primary form" check in
     * ClientController, the credential-prefill source when adding a new
     * form) rely on ->first() meaning "the client's original form", which
     * needs a deterministic order to mean anything.
     */
    #[ORM\OneToMany(mappedBy: 'client', targetEntity: HelloAssoConfig::class, cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $helloAssoConfigs;

    #[ORM\OneToOne(mappedBy: 'client', targetEntity: CyclosConfig::class, cascade: ['persist', 'remove'])]
    private ?CyclosConfig $cyclosConfig = null;

    #[ORM\OneToOne(mappedBy: 'client', targetEntity: ClientSetting::class, cascade: ['persist', 'remove'])]
    private ?ClientSetting $setting = null;

    /**
     * Optional per-client cosmetic overrides (e-mail wording, Cyclos
     * description prefix...). Absent for most clients — a null relation means
     * "application defaults everywhere", see ClientCustomization.
     */
    #[ORM\OneToOne(mappedBy: 'client', targetEntity: ClientCustomization::class, cascade: ['persist', 'remove'])]
    private ?ClientCustomization $customization = null;

    #[ORM\OneToMany(mappedBy: 'client', targetEntity: Payment::class)]
    private Collection $payments;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->payments = new ArrayCollection();
        $this->helloAssoConfigs = new ArrayCollection();
        $this->webhookToken = self::generateWebhookToken();
    }

    private static function generateWebhookToken(): string
    {
        return bin2hex(random_bytes(32));
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

    public function getWebhookToken(): string
    {
        return $this->webhookToken;
    }

    /**
     * Issues a fresh webhook token. The client's HelloAsso notification URL must
     * be updated accordingly, otherwise incoming payloads stop being accepted.
     */
    public function regenerateWebhookToken(): static
    {
        $this->webhookToken = self::generateWebhookToken();

        return $this;
    }

    public function getLegacyWebhookLastSeenAt(): ?\DateTimeImmutable
    {
        return $this->legacyWebhookLastSeenAt;
    }

    public function setLegacyWebhookLastSeenAt(?\DateTimeImmutable $legacyWebhookLastSeenAt): static
    {
        $this->legacyWebhookLastSeenAt = $legacyWebhookLastSeenAt;

        return $this;
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

    public function getPaymentProvider(): PaymentProvider
    {
        return $this->paymentProvider;
    }

    public function setPaymentProvider(PaymentProvider $paymentProvider): static
    {
        $this->paymentProvider = $paymentProvider;

        return $this;
    }

    public function usesStripe(): bool
    {
        return $this->paymentProvider === PaymentProvider::Stripe;
    }

    public function usesHelloAsso(): bool
    {
        return $this->paymentProvider === PaymentProvider::HelloAsso;
    }

    public function getStripeConfig(): ?StripeConfig
    {
        return $this->stripeConfig;
    }

    public function setStripeConfig(?StripeConfig $stripeConfig): static
    {
        $this->stripeConfig = $stripeConfig;
        if ($stripeConfig !== null && $stripeConfig->getClient() !== $this) {
            $stripeConfig->setClient($this);
        }

        return $this;
    }

    /**
     * @return Collection<int, HelloAssoConfig>
     */
    public function getHelloAssoConfigs(): Collection
    {
        return $this->helloAssoConfigs;
    }

    /**
     * @return Collection<int, HelloAssoConfig>
     */
    public function getActiveHelloAssoConfigs(): Collection
    {
        return $this->helloAssoConfigs->filter(static fn (HelloAssoConfig $config) => $config->isActive());
    }

    /**
     * The client's original HelloAsso form (oldest by id) — the one it had
     * before it's ever had a second. Unlike any form added afterward, it can
     * never be deleted (see ClientController::deleteHelloAssoConfig()),
     * only deactivated: a client that has only ever had one form shouldn't
     * lose it to a stray click just because it happens to have no payment
     * history yet.
     */
    public function getPrimaryHelloAssoConfig(): ?HelloAssoConfig
    {
        return $this->helloAssoConfigs->isEmpty() ? null : $this->helloAssoConfigs->first();
    }

    public function addHelloAssoConfig(HelloAssoConfig $config): static
    {
        if (!$this->helloAssoConfigs->contains($config)) {
            $this->helloAssoConfigs->add($config);
            $config->setClient($this);
        }

        return $this;
    }

    public function removeHelloAssoConfig(HelloAssoConfig $config): static
    {
        $this->helloAssoConfigs->removeElement($config);

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

    public function getCustomization(): ?ClientCustomization
    {
        return $this->customization;
    }

    public function setCustomization(?ClientCustomization $customization): static
    {
        $this->customization = $customization;
        if ($customization !== null && $customization->getClient() !== $this) {
            $customization->setClient($this);
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
