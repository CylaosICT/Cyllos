<?php

namespace App\Payment;

use App\Entity\CheckoutOrder;
use App\Entity\CheckoutOrderStatus;
use App\Entity\Client;
use App\Integration\Cyclos\CyclosClient;
use App\Integration\Stripe\StripeClient;
use App\Integration\Stripe\StripeException;
use App\Repository\EmailAliasRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Starts a Stripe top-up for a client: validates what the payer asked for,
 * checks up front that the Cyclos account exists (so nobody pays for a credit
 * that is then impossible), records a CheckoutOrder and opens the Stripe
 * Checkout Session whose URL the payer is sent to.
 *
 * Every rule that matters (amount bounds, account existence, client state) is
 * enforced here, server-side — the payment site is only a front.
 */
class CheckoutService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EmailAliasRepository $emailAliasRepository,
        private readonly CyclosClient $cyclosClient,
        private readonly StripeClient $stripeClient,
        private readonly LoggerInterface $logger,
        private readonly string $paySiteBaseUrl,
    ) {
    }

    /**
     * @throws CheckoutException
     */
    public function start(Client $client, string $email, int $amountCents): CheckoutStart
    {
        $stripe = $this->requireAvailable($client);

        $email = $this->requireValidEmail($email);

        if ($amountCents < $stripe->getMinAmount() * 100 || $amountCents > $stripe->getMaxAmount() * 100) {
            throw new CheckoutException(CheckoutException::INVALID_AMOUNT);
        }

        $this->resolveAccount($client, $email);

        $order = new CheckoutOrder($client, $email, $amountCents);
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        $base = rtrim($this->paySiteBaseUrl, '/') . '/' . rawurlencode($client->getSlug());

        try {
            $session = $this->stripeClient->createCheckoutSession(
                $stripe,
                $order,
                'Alimentation de compte — ' . $client->getName(),
                $base . '/retour/' . rawurlencode($order->getReference()),
                $base . '?annule=' . rawurlencode($order->getReference()),
            );
        } catch (StripeException $e) {
            $order->setStatus(CheckoutOrderStatus::Failed);
            $this->entityManager->flush();
            $this->logger->error('Checkout for client {client} failed: {message}', ['client' => $client->getSlug(), 'message' => $e->getMessage()]);

            throw new CheckoutException(CheckoutException::PROVIDER_ERROR, $e->getMessage());
        }

        $order->setStripeSessionId($session->id);
        $this->entityManager->flush();

        return new CheckoutStart($order->getReference(), $session->url);
    }
    /**
     * Pre-check for the payment site's "confirm your account" step: does this
     * e-mail match a Cyclos account that can receive a credit, and of which kind?
     * Reveals nothing else about the account.
     *
     * @return 'professional'|'individual'
     *
     * @throws CheckoutException
     */
    public function checkAccount(Client $client, string $email): string
    {
        $this->requireAvailable($client);
        $user = $this->resolveAccount($client, $this->requireValidEmail($email));

        return \in_array($user->groupInternalName, $client->getCyclosConfig()?->getGroupsProInternalList() ?? [], true) ? 'professional' : 'individual';
    }

    private function requireAvailable(Client $client): \App\Entity\StripeConfig
    {
        $stripe = $client->getStripeConfig();
        if (!$client->isActive() || !$client->usesStripe() || $stripe === null || !$stripe->isActive() || $client->getCyclosConfig() === null || $this->paySiteBaseUrl === '') {
            throw new CheckoutException(CheckoutException::CLIENT_UNAVAILABLE);
        }

        return $stripe;
    }

    private function requireValidEmail(string $email): string
    {
        $email = trim($email);
        if ($email === '' || mb_strlen($email) > 255 || filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
            throw new CheckoutException(CheckoutException::INVALID_EMAIL);
        }

        return $email;
    }

    /**
     * Resolves the Cyclos account the same way the crediting will (e-mail
     * correction rule first) and makes sure its group can be credited.
     *
     * @throws CheckoutException
     */
    private function resolveAccount(Client $client, string $email): \App\Integration\Cyclos\CyclosUser
    {
        $cyclos = $client->getCyclosConfig();
        \assert($cyclos !== null);

        $alias = $this->emailAliasRepository->findOneByClientAndSourceEmail($client, $email);
        $user = $this->cyclosClient->findUserByEmail($cyclos, $alias !== null ? $alias->getTargetEmail() : $email);
        if ($user === null) {
            throw new CheckoutException(CheckoutException::ACCOUNT_NOT_FOUND);
        }
        if ($this->cyclosClient->resolveEmissionType($cyclos, $user) === null) {
            throw new CheckoutException(CheckoutException::ACCOUNT_NOT_ELIGIBLE);
        }

        return $user;
    }
}
