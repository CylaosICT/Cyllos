<?php

namespace App\Controller;

use App\Entity\Client;
use App\Payment\CheckoutException;
use App\Payment\CheckoutService;
use App\Repository\ClientRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Machine-to-machine endpoints used by the separate payment site (cyllos-pay):
 *  - /internal/account-check: "does this e-mail match a Cyclos account that can
 *    be credited?" (the confirmation step, before any payment);
 *  - /internal/checkout: "start a top-up for this client, e-mail and amount" ->
 *    the Stripe URL to send the payer to.
 * The payment site holds no Stripe key and no Cyclos access; it can only ask
 * Cyllos, which validates everything.
 *
 * Authenticated by an HMAC-SHA256 of "{timestamp}.{raw body}" with the shared
 * secret (PAY_INTERNAL_SECRET), sent in X-Cyllos-Timestamp / X-Cyllos-Signature,
 * with a short validity window against replays. Disabled (503) when no secret
 * is configured.
 */
class InternalCheckoutController extends AbstractController
{
    public const MAX_CLOCK_SKEW_SECONDS = 300;

    public function __construct(
        private readonly ClientRepository $clientRepository,
        private readonly CheckoutService $checkoutService,
        private readonly LoggerInterface $logger,
        private readonly RateLimiterFactoryInterface $internalCheckoutLimiter,
        private readonly RateLimiterFactoryInterface $internalCheckoutEmailLimiter,
        private readonly RateLimiterFactoryInterface $internalAccountCheckLimiter,
        private readonly RateLimiterFactoryInterface $internalAccountCheckEmailLimiter,
        private readonly string $internalSecret,
    ) {
    }

    #[Route(path: '/internal/account-check', name: 'internal_account_check', methods: ['POST'])]
    public function accountCheck(Request $request): Response
    {
        $data = $this->payload($request, ['clientSlug' => 'string', 'email' => 'string', 'clientIp' => 'string']);
        if ($data instanceof Response) {
            return $data;
        }

        $limited = $this->throttle($this->internalAccountCheckLimiter, $this->internalAccountCheckEmailLimiter, $data['clientIp'], $data['email']);
        if ($limited !== null) {
            return $limited;
        }

        $client = $this->clientRepository->findOneBySlug($data['clientSlug']);
        if ($client === null) {
            return new JsonResponse(['error' => CheckoutException::CLIENT_UNAVAILABLE], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            return new JsonResponse(['accountType' => $this->checkoutService->checkAccount($client, $data['email'])]);
        } catch (CheckoutException $e) {
            return $this->failure($e);
        }
    }

    #[Route(path: '/internal/checkout', name: 'internal_checkout', methods: ['POST'])]
    public function start(Request $request): Response
    {
        $data = $this->payload($request, ['clientSlug' => 'string', 'email' => 'string', 'amountCents' => 'int', 'clientIp' => 'string']);
        if ($data instanceof Response) {
            return $data;
        }

        $limited = $this->throttle($this->internalCheckoutLimiter, $this->internalCheckoutEmailLimiter, $data['clientIp'], $data['email']);
        if ($limited !== null) {
            return $limited;
        }

        $client = $this->clientRepository->findOneBySlug($data['clientSlug']);
        if ($client === null) {
            return new JsonResponse(['error' => CheckoutException::CLIENT_UNAVAILABLE], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $start = $this->checkoutService->start($client, $data['email'], $data['amountCents']);
        } catch (CheckoutException $e) {
            return $this->failure($e);
        }

        return new JsonResponse(['reference' => $start->reference, 'redirectUrl' => $start->redirectUrl]);
    }

    /**
     * Authenticates the call and decodes its JSON body.
     *
     * @param array<string, 'string'|'int'> $schema key => expected type
     *
     * @return array<string, mixed>|Response the decoded body, or the error response
     */
    private function payload(Request $request, array $schema): array|Response
    {
        if ($this->internalSecret === '') {
            return new JsonResponse(['error' => 'disabled'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $body = $request->getContent();
        if (!$this->isAuthentic($request, $body)) {
            $this->logger->warning('Internal call with a missing or invalid signature');

            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($body, true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'bad_request'], Response::HTTP_BAD_REQUEST);
        }
        foreach ($schema as $key => $type) {
            $value = $data[$key] ?? null;
            if (($type === 'string' && !\is_string($value)) || ($type === 'int' && !\is_int($value))) {
                return new JsonResponse(['error' => 'bad_request'], Response::HTTP_BAD_REQUEST);
            }
        }

        return $data;
    }

    /**
     * Limits are keyed on the payer's IP and on the e-mail, both vouched for by
     * the signed body: the payment site's own address is always the same.
     */
    private function throttle(RateLimiterFactoryInterface $byIp, RateLimiterFactoryInterface $byEmail, string $ip, string $email): ?Response
    {
        $emailKey = hash('sha256', mb_strtolower(trim($email)));
        if (!$byIp->create($ip)->consume()->isAccepted() || !$byEmail->create($emailKey)->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'rate_limited'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        return null;
    }

    private function failure(CheckoutException $e): Response
    {
        $status = $e->reason === CheckoutException::PROVIDER_ERROR ? Response::HTTP_BAD_GATEWAY : Response::HTTP_UNPROCESSABLE_ENTITY;

        return new JsonResponse(['error' => $e->reason], $status);
    }

    private function isAuthentic(Request $request, string $body): bool
    {
        $timestamp = $request->headers->get('X-Cyllos-Timestamp', '');
        $signature = $request->headers->get('X-Cyllos-Signature', '');

        if (!ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::MAX_CLOCK_SKEW_SECONDS) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $timestamp . '.' . $body, $this->internalSecret), $signature);
    }
}
