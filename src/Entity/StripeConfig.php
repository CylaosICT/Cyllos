<?php

namespace App\Entity;

use App\Repository\StripeConfigRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A client's own Stripe account credentials (one per client). Payments go
 * straight to the client's Stripe account — Cylaos never holds the funds.
 *
 * Both secrets are stored encrypted at rest (see SecretEncryptor) and are
 * never displayed again once saved; only a non-sensitive hint of the API key
 * (mode + last characters) is kept in clear for the back office.
 */
#[ORM\Entity(repositoryClass: StripeConfigRepository::class)]
class StripeConfig
{
    public const SECRET_KEY_PATTERN = '/^(sk|rk)_(test|live)_[A-Za-z0-9]{10,}$/';
    public const WEBHOOK_SECRET_PATTERN = '/^whsec_[A-Za-z0-9]{10,}$/';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'stripeConfig', targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    private Client $client;

    #[ORM\Column]
    private bool $active = true;

    /**
     * Secret or restricted API key (sk_… / rk_…), encrypted.
     */
    #[ORM\Column(type: 'text')]
    private string $secretKeyEncrypted = '';

    /**
     * Webhook signing secret (whsec_…), encrypted. Used to verify the
     * Stripe-Signature header of the events Stripe sends for this account.
     */
    #[ORM\Column(type: 'text')]
    private string $webhookSecretEncrypted = '';

    /**
     * Display-only hint of the API key, e.g. "rk_live_…a1b2". Safe to show.
     */
    #[ORM\Column(length: 40)]
    private string $secretKeyHint = '';

    /**
     * True for a live-mode key, false for a test-mode key.
     */
    #[ORM\Column]
    private bool $liveMode = false;

    #[ORM\Column]
    #[Assert\Positive]
    private int $minAmount = 5;

    #[ORM\Column]
    #[Assert\Positive]
    private int $maxAmount = 250;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClient(): ?Client
    {
        // Not initialised until the config is attached to a client.
        return $this->client ?? null;
    }

    public function setClient(Client $client): static
    {
        $this->client = $client;

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

    public function getSecretKeyEncrypted(): string
    {
        return $this->secretKeyEncrypted;
    }

    public function setSecretKeyEncrypted(string $secretKeyEncrypted): static
    {
        $this->secretKeyEncrypted = $secretKeyEncrypted;

        return $this;
    }

    public function getWebhookSecretEncrypted(): string
    {
        return $this->webhookSecretEncrypted;
    }

    public function setWebhookSecretEncrypted(string $webhookSecretEncrypted): static
    {
        $this->webhookSecretEncrypted = $webhookSecretEncrypted;

        return $this;
    }

    public function getSecretKeyHint(): string
    {
        return $this->secretKeyHint;
    }

    public function isLiveMode(): bool
    {
        return $this->liveMode;
    }

    /**
     * Records the non-sensitive hint (and live/test mode) derived from the
     * plaintext key, so the back office can tell which key is configured
     * without ever being able to read it back.
     */
    public function rememberSecretKeyHint(#[\SensitiveParameter] string $plainSecretKey): static
    {
        $prefix = preg_match('/^(?:sk|rk)_(?:test|live)_/', $plainSecretKey, $matches) === 1 ? $matches[0] : '';
        $this->liveMode = str_ends_with($prefix, '_live_');
        $this->secretKeyHint = $prefix . '…' . substr($plainSecretKey, -4);

        return $this;
    }

    public function getMinAmount(): int
    {
        return $this->minAmount;
    }

    public function setMinAmount(int $minAmount): static
    {
        $this->minAmount = $minAmount;

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

    #[Assert\Callback]
    public function validateAmountRange(\Symfony\Component\Validator\Context\ExecutionContextInterface $context): void
    {
        if ($this->minAmount > $this->maxAmount) {
            $context->buildViolation('Le montant minimum ne peut pas dépasser le montant maximum.')
                ->atPath('minAmount')
                ->addViolation();
        }
    }
}
