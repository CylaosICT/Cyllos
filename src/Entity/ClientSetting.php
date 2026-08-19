<?php

namespace App\Entity;

use App\Repository\ClientSettingRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ClientSettingRepository::class)]
class ClientSetting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'setting', targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    private ?Client $client = null;

    #[ORM\Column]
    private bool $paymentCyclosEnabled = false;

    #[ORM\Column]
    private bool $paymentAutomaticEnabled = false;

    /**
     * Primary email for this client: where technical alerts and (if enabled)
     * payment notifications are sent.
     */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Email]
    private string $mailRecipient = '';

    /**
     * Whether to email the client (at Client::contactEmail, not mailRecipient)
     * for each successful / failed automatic payment. Two independent toggles
     * on purpose — a client may want to know about failures without being
     * emailed for every single successful credit, or vice versa.
     */
    #[ORM\Column]
    private bool $notifySuccessOnPayment = false;

    #[ORM\Column]
    private bool $notifyFailureOnPayment = false;

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

    public function isPaymentCyclosEnabled(): bool
    {
        return $this->paymentCyclosEnabled;
    }

    public function setPaymentCyclosEnabled(bool $paymentCyclosEnabled): static
    {
        $this->paymentCyclosEnabled = $paymentCyclosEnabled;

        return $this;
    }

    public function isPaymentAutomaticEnabled(): bool
    {
        return $this->paymentAutomaticEnabled;
    }

    public function setPaymentAutomaticEnabled(bool $paymentAutomaticEnabled): static
    {
        $this->paymentAutomaticEnabled = $paymentAutomaticEnabled;

        return $this;
    }

    public function getMailRecipient(): string
    {
        return $this->mailRecipient;
    }

    public function setMailRecipient(string $mailRecipient): static
    {
        $this->mailRecipient = $mailRecipient;

        return $this;
    }

    public function isNotifySuccessOnPayment(): bool
    {
        return $this->notifySuccessOnPayment;
    }

    public function setNotifySuccessOnPayment(bool $notifySuccessOnPayment): static
    {
        $this->notifySuccessOnPayment = $notifySuccessOnPayment;

        return $this;
    }

    public function isNotifyFailureOnPayment(): bool
    {
        return $this->notifyFailureOnPayment;
    }

    public function setNotifyFailureOnPayment(bool $notifyFailureOnPayment): static
    {
        $this->notifyFailureOnPayment = $notifyFailureOnPayment;

        return $this;
    }
}
