<?php

namespace App\Form;

use App\Entity\Client;
use App\Entity\ClientSetting;
use App\Entity\CyclosConfig;
use App\Entity\HelloAssoConfig;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Holds the in-progress state of the 4-step "new client" wizard across
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
