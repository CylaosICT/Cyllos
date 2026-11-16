<?php

namespace App\Entity;

use App\Repository\PaymentRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PaymentRepository::class)]
#[ORM\UniqueConstraint(name: 'client_helloasso_payment_unique', columns: ['client_id', 'hello_asso_payment_id'])]
#[ORM\UniqueConstraint(name: 'client_payment_reference_unique', columns: ['client_id', 'reference'])]
#[ORM\Index(columns: ['status'])]
class Payment
{
    public const ERROR_LENGTH = 700;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'payments', targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Client $client = null;

    /**
     * The HelloAsso form this payment came from, captured once at creation.
     * Needed later (manual credit, late-payment retry, catch-up "credit all")
     * to know which HelloAsso credentials/settings to use — by then, the
     * client may have more than one config and there's no other way to tell
     * them apart. Restricted from deletion (see HelloAssoConfig), so this
     * never dangles. Null for a Stripe payment.
     */
    #[ORM\ManyToOne(targetEntity: HelloAssoConfig::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?HelloAssoConfig $helloAssoConfig = null;

    /**
     * Payment id as given by HelloAsso, unique per client. Null for a Stripe
     * payment (identified by $reference instead).
     */
    #[ORM\Column(nullable: true)]
    private ?int $helloAssoPaymentId = null;

    #[ORM\Column(length: 20, enumType: PaymentProvider::class)]
    private PaymentProvider $provider = PaymentProvider::HelloAsso;

    /**
     * Stripe payments only: the CheckoutOrder reference ("S-XXXXXXXXXX"),
     * unique per client. It plays the role the HelloAsso payment id plays for
     * HelloAsso payments (Cyclos description suffix, anti-duplicate key).
     */
    #[ORM\Column(length: 20, nullable: true)]
    private ?string $reference = null;

    #[ORM\ManyToOne(targetEntity: CheckoutOrder::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?CheckoutOrder $checkoutOrder = null;

    #[ORM\Column]
    private \DateTimeImmutable $paymentDate;

    /**
     * In the currency's main unit (euros), not cents.
     */
    #[ORM\Column]
    private float $amount;

    #[ORM\Column(length: 255)]
    private string $payerFirstName;

    #[ORM\Column(length: 255)]
    private string $payerLastName;

    /**
     * The email actually used for the Cyclos credit — starts out identical to
     * payerEmail, but gets overwritten on a successful credit if an
     * EmailAlias rule or the HelloAsso alternative-email fallback resolved a
     * different one. Never use this to display "the HelloAsso email" once a
     * payment may have been credited — use payerEmail for that.
     */
    #[ORM\Column(length: 255)]
    private string $email;

    /**
     * The payer's email exactly as reported by HelloAsso, captured once at
     * creation and never modified afterward — unlike email above, this
     * stays reliable for display and for looking up an EmailAlias rule
     * regardless of what happened during crediting.
     */
    #[ORM\Column(length: 255)]
    private string $payerEmail;

    #[ORM\Column]
    private \DateTimeImmutable $insertionDate;

    #[ORM\Column(length: 20, enumType: PaymentStatus::class)]
    private PaymentStatus $status;

    #[ORM\Column(type: 'text', length: self::ERROR_LENGTH, nullable: true)]
    private ?string $error = null;

    public function __construct(
        Client $client,
        ?HelloAssoConfig $helloAssoConfig,
        ?int $helloAssoPaymentId,
        \DateTimeImmutable $paymentDate,
        float $amount,
        string $payerFirstName,
        string $payerLastName,
        string $email,
        PaymentProvider $provider = PaymentProvider::HelloAsso,
        ?string $reference = null,
        ?CheckoutOrder $checkoutOrder = null,
    ) {
        $this->client = $client;
        $this->helloAssoConfig = $helloAssoConfig;
        $this->helloAssoPaymentId = $helloAssoPaymentId;
        $this->provider = $provider;
        $this->reference = $reference;
        $this->checkoutOrder = $checkoutOrder;
        $this->paymentDate = $paymentDate;
        $this->amount = $amount;
        $this->payerFirstName = $payerFirstName;
        $this->payerLastName = $payerLastName;
        $this->email = $email;
        $this->payerEmail = $email;
        $this->insertionDate = new \DateTimeImmutable();
        $this->status = PaymentStatus::Todo;
    }

    /**
     * A Stripe payment, built from the CheckoutOrder it settles.
     */
    public static function forStripeOrder(CheckoutOrder $order, \DateTimeImmutable $paymentDate, string $payerName): self
    {
        return new self(
            client: $order->getClient(),
            helloAssoConfig: null,
            helloAssoPaymentId: null,
            paymentDate: $paymentDate,
            amount: $order->getAmount(),
            payerFirstName: '',
            payerLastName: $payerName,
            email: $order->getEmail(),
            provider: PaymentProvider::Stripe,
            reference: $order->getReference(),
            checkoutOrder: $order,
        );
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClient(): Client
    {
        return $this->client;
    }

    public function getProvider(): PaymentProvider
    {
        return $this->provider;
    }

    public function getCheckoutOrder(): ?CheckoutOrder
    {
        return $this->checkoutOrder;
    }

    /**
     * The payment's identifier as shown to humans and written in the Cyclos
     * description: the HelloAsso payment id, or the Stripe order reference.
     */
    public function getReference(): string
    {
        return $this->reference ?? (string) $this->helloAssoPaymentId;
    }

    /**
     * Where the payment came from, for lists: the HelloAsso form label, or
     * "Stripe".
     */
    public function getSourceLabel(): string
    {
        return $this->helloAssoConfig?->getLabel() ?? $this->provider->label();
    }

    /**
     * Whether the source is still active: the HelloAsso form, or the client's
     * Stripe account for a Stripe payment.
     */
    public function isSourceActive(): bool
    {
        if ($this->helloAssoConfig !== null) {
            return $this->helloAssoConfig->isActive();
        }

        return $this->getClient()->getStripeConfig()?->isActive() ?? false;
    }

    public function getHelloAssoConfig(): ?HelloAssoConfig
    {
        return $this->helloAssoConfig;
    }

    public function getHelloAssoPaymentId(): ?int
    {
        return $this->helloAssoPaymentId;
    }

    public function getPaymentDate(): \DateTimeImmutable
    {
        return $this->paymentDate;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function getPayerFirstName(): string
    {
        return $this->payerFirstName;
    }

    public function getPayerLastName(): string
    {
        return $this->payerLastName;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getPayerEmail(): string
    {
        return $this->payerEmail;
    }

    public function getInsertionDate(): \DateTimeImmutable
    {
        return $this->insertionDate;
    }

    public function getStatus(): PaymentStatus
    {
        return $this->status;
    }

    public function setStatus(PaymentStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(?string $error): static
    {
        if ($error !== null && \strlen($error) > self::ERROR_LENGTH) {
            $error = substr($error, 0, self::ERROR_LENGTH - 3) . '...';
        }
        $this->error = $error;

        return $this;
    }
}
