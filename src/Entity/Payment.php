<?php

namespace App\Entity;

use App\Repository\PaymentRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PaymentRepository::class)]
#[ORM\UniqueConstraint(name: 'client_helloasso_payment_unique', columns: ['client_id', 'hello_asso_payment_id'])]
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
     * Payment id as given by HelloAsso, unique per client.
     */
    #[ORM\Column]
    private int $helloAssoPaymentId;

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
        int $helloAssoPaymentId,
        \DateTimeImmutable $paymentDate,
        float $amount,
        string $payerFirstName,
        string $payerLastName,
        string $email,
    ) {
        $this->client = $client;
        $this->helloAssoPaymentId = $helloAssoPaymentId;
        $this->paymentDate = $paymentDate;
        $this->amount = $amount;
        $this->payerFirstName = $payerFirstName;
        $this->payerLastName = $payerLastName;
        $this->email = $email;
        $this->payerEmail = $email;
        $this->insertionDate = new \DateTimeImmutable();
        $this->status = PaymentStatus::Todo;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getClient(): Client
    {
        return $this->client;
    }

    public function getHelloAssoPaymentId(): int
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
