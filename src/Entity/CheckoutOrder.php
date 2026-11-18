<?php

namespace App\Entity;

use App\Repository\CheckoutOrderRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A Stripe Checkout top-up started from the payment site: who wants to credit
 * which Cyclos account (by e-mail) for how much, and the Stripe Checkout
 * Session carrying it. Created by CheckoutService before the payer is sent to
 * Stripe; turned into a Payment by the webhook once Stripe confirms the money.
 */
#[ORM\Entity(repositoryClass: CheckoutOrderRepository::class)]
#[ORM\UniqueConstraint(name: 'checkout_order_reference_unique', columns: ['reference'])]
#[ORM\UniqueConstraint(name: 'checkout_order_session_unique', columns: ['stripe_session_id'])]
#[ORM\Index(columns: ['status'])]
class CheckoutOrder
{
    /** Crockford-style alphabet: no 0/O/1/I/L, easy to read out loud. */
    private const REFERENCE_ALPHABET = 'ABCDEFGHJKMNPQRSTVWXYZ23456789';
    private const REFERENCE_LENGTH = 10;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Client $client;

    /**
     * Public, unguessable-enough reference ("S-XXXXXXXXXX"): carried in the
     * Stripe session metadata, used as the Cyclos transaction suffix and on
     * the payer's return page.
     */
    #[ORM\Column(length: 20)]
    private string $reference;

    /**
     * E-mail of the Cyclos account to credit (what the payer typed).
     */
    #[ORM\Column(length: 255)]
    private string $email;

    #[ORM\Column]
    private int $amountCents;

    /**
     * What the Cyclos account is credited with, in cents of the Cyclos unit.
     * Null (older orders) or equal to amountCents: credit = payment.
     */
    #[ORM\Column(nullable: true)]
    private ?int $creditAmountCents = null;

    #[ORM\Column(length: 20, enumType: CheckoutOrderStatus::class)]
    private CheckoutOrderStatus $status = CheckoutOrderStatus::Pending;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeSessionId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripePaymentIntentId = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    public function __construct(Client $client, string $email, int $amountCents, ?int $creditAmountCents = null)
    {
        $this->client = $client;
        $this->email = $email;
        $this->amountCents = $amountCents;
        $this->creditAmountCents = $creditAmountCents;
        $this->reference = self::generateReference();
        $this->createdAt = new \DateTimeImmutable();
    }

    private static function generateReference(): string
    {
        $alphabetLength = \strlen(self::REFERENCE_ALPHABET);
        $code = '';
        for ($i = 0; $i < self::REFERENCE_LENGTH; ++$i) {
            $code .= self::REFERENCE_ALPHABET[random_int(0, $alphabetLength - 1)];
        }

        return 'S-' . $code;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClient(): Client
    {
        return $this->client;
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getAmountCents(): int
    {
        return $this->amountCents;
    }

    public function getAmount(): float
    {
        return $this->amountCents / 100;
    }

    /** What the Cyclos account receives (the payment itself unless a different credit was configured). */
    public function getCreditAmountCents(): int
    {
        return $this->creditAmountCents ?? $this->amountCents;
    }

    public function getCreditAmount(): float
    {
        return $this->getCreditAmountCents() / 100;
    }

    public function getStatus(): CheckoutOrderStatus
    {
        return $this->status;
    }

    public function setStatus(CheckoutOrderStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getStripeSessionId(): ?string
    {
        return $this->stripeSessionId;
    }

    public function setStripeSessionId(?string $stripeSessionId): static
    {
        $this->stripeSessionId = $stripeSessionId;

        return $this;
    }

    public function getStripePaymentIntentId(): ?string
    {
        return $this->stripePaymentIntentId;
    }

    public function setStripePaymentIntentId(?string $stripePaymentIntentId): static
    {
        $this->stripePaymentIntentId = $stripePaymentIntentId;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function markPaid(?string $paymentIntentId, \DateTimeImmutable $paidAt): static
    {
        $this->status = CheckoutOrderStatus::Paid;
        $this->stripePaymentIntentId = $paymentIntentId ?? $this->stripePaymentIntentId;
        $this->paidAt = $paidAt;

        return $this;
    }
}
