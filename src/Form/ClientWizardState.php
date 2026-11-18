<?php

namespace App\Form;

use App\Entity\Client;
use App\Entity\ClientSetting;
use App\Entity\CyclosConfig;
use App\Entity\HelloAssoConfig;
use App\Entity\PaymentProvider;
use App\Entity\StripeConfig;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Holds the in-progress state of the 5-step "new client" wizard across
 * requests (session-backed), and hydrates transient entities from it so each
 * step's form can be pre-filled when the user navigates back and forth.
 */
class ClientWizardState
{
    private const SESSION_KEY = 'client_wizard';

    private readonly SessionInterface $session;

    public function __construct(RequestStack $requestStack)
    {
        $this->session = $requestStack->getSession();
    }

    public function hasStep(string $step): bool
    {
        return isset($this->all()[$step]);
    }

    public function set(string $step, array $data): void
    {
        $state = $this->all();
        $state[$step] = $data;
        $this->session->set(self::SESSION_KEY, $state);
    }

    public function clear(): void
    {
        $this->session->remove(self::SESSION_KEY);
    }

    public function clientInfo(): Client
    {
        $client = new Client();
        $data = $this->all()['info'] ?? [];
        $client->setName($data['name'] ?? '');
        $client->setSlug($data['slug'] ?? '');
        $client->setActive($data['active'] ?? true);

        return $client;
    }

    public function helloAssoConfig(): HelloAssoConfig
    {
        $config = new HelloAssoConfig();
        $data = $this->all()['helloAsso'] ?? [];
        $config->setLabel($data['label'] ?? '');
        if (isset($data['apiUrl'])) {
            $config->setApiUrl($data['apiUrl']);
        }
        $config->setHelloAssoClientId($data['helloAssoClientId'] ?? '');
        $config->setOrganizationSlug($data['organizationSlug'] ?? '');
        $config->setFormType($data['formType'] ?? 'PaymentForm');
        $config->setFormSlug($data['formSlug'] ?? '');
        $config->setMaxAmount($data['maxAmount'] ?? 250);
        $config->setExtraMailFieldName($data['extraMailFieldName'] ?? null);
        $config->setFetchNbDays($data['fetchNbDays'] ?? 5);

        return $config;
    }

    public function provider(): ?PaymentProvider
    {
        $value = $this->all()['provider']['value'] ?? null;

        return \is_string($value) ? PaymentProvider::tryFrom($value) : null;
    }

    public function stripeConfig(): StripeConfig
    {
        $config = new StripeConfig();
        $data = $this->all()['stripe'] ?? [];
        $config->setMinAmount($data['minAmount'] ?? 5);
        $config->setMaxAmount($data['maxAmount'] ?? 250);
        $config->setPayAmountMode($data['payAmountMode'] ?? null);
        $config->setPayAmountChoices($data['payAmountChoices'] ?? null);
        $config->setPayFixedAmount($data['payFixedAmount'] ?? null);
        $config->setPayFixedCredit($data['payFixedCredit'] ?? null);
        $config->setPayCreditRate($data['payCreditRate'] ?? null);
        $config->setPayCreditUnit($data['payCreditUnit'] ?? null);
        $config->setPayQuickAmounts($data['payQuickAmounts'] ?? null);
        $config->setBrandPrimaryColor($data['brandPrimaryColor'] ?? null);
        $config->setBrandBackgroundColor($data['brandBackgroundColor'] ?? null);
        $config->setBrandSurfaceColor($data['brandSurfaceColor'] ?? null);
        $config->setBrandTextColor($data['brandTextColor'] ?? null);

        return $config;
    }

    /** Plaintext Stripe secrets captured during the wizard, applied (encrypted) at the final step. */
    public function stripeSecretKey(): string
    {
        return $this->all()['stripe']['secretKey'] ?? '';
    }

    public function stripeWebhookSecret(): string
    {
        return $this->all()['stripe']['webhookSecret'] ?? '';
    }

    /**
     * Whether the step holding the chosen provider's credentials is done.
     */
    public function hasProviderConfigStep(): bool
    {
        return match ($this->provider()) {
            PaymentProvider::HelloAsso => $this->hasStep('helloAsso'),
            PaymentProvider::Stripe => $this->hasStep('stripe'),
            default => false,
        };
    }

    public function cyclosConfig(): CyclosConfig
    {
        $config = new CyclosConfig();
        $data = $this->all()['cyclos'] ?? [];
        $config->setBaseUrl($data['baseUrl'] ?? '');
        $config->setTechnicalUserId($data['technicalUserId'] ?? '');
        $config->setGroupProInternal($data['groupProInternal'] ?? '');
        $config->setGroupsPartInternal($data['groupsPartInternal'] ?? '');
        $config->setEmissionProInternal($data['emissionProInternal'] ?? '');
        $config->setEmissionPartInternal($data['emissionPartInternal'] ?? '');

        return $config;
    }

    public function clientSetting(): ClientSetting
    {
        $setting = new ClientSetting();
        $data = $this->all()['setting'] ?? [];
        $setting->setPaymentCyclosEnabled($data['paymentCyclosEnabled'] ?? false);
        $setting->setPaymentAutomaticEnabled($data['paymentAutomaticEnabled'] ?? false);
        $setting->setMailRecipient($data['mailRecipient'] ?? '');

        return $setting;
    }

    /** Plaintext secrets captured during the wizard, applied (encrypted) at the final step. */
    public function helloAssoSecret(): string
    {
        return $this->all()['helloAsso']['clientSecret'] ?? '';
    }

    public function cyclosPassword(): string
    {
        return $this->all()['cyclos']['password'] ?? '';
    }

    private function all(): array
    {
        return $this->session->get(self::SESSION_KEY, []);
    }
}
