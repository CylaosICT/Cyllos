<?php

namespace App\Controller;

use App\Payment\PaymentProcessor;
use App\Repository\ClientRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class WebhookController extends AbstractController
{
    public function __construct(
        private readonly ClientRepository $clientRepository,
        private readonly PaymentProcessor $paymentProcessor,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(path: '/webhook/helloasso/{clientSlug}', name: 'webhook_helloasso', methods: ['POST'])]
    public function receive(string $clientSlug, Request $request): Response
    {
        $client = $this->clientRepository->findOneBySlug($clientSlug);

        if ($client === null || !$client->isActive()) {
            $this->logger->warning('HelloAsso webhook received for unknown or inactive client "{slug}"', ['slug' => $clientSlug]);

            return new Response(status: Response::HTTP_NOT_FOUND);
        }

        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            $this->logger->warning('HelloAsso webhook received malformed JSON for client "{slug}"', ['slug' => $clientSlug]);

            return new Response(status: Response::HTTP_BAD_REQUEST);
        }

        $this->logger->info('HelloAsso webhook received for client "{slug}"', ['slug' => $clientSlug]);

        $result = $this->paymentProcessor->handleWebhookNotification($client, $payload);

        return new JsonResponse([
            'status' => $result?->status->value ?? 'ignored',
        ]);
    }
}
