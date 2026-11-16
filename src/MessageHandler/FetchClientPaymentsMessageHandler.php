<?php

namespace App\MessageHandler;

use App\Integration\HelloAsso\HelloAssoRateLimitedException;
use App\Message\FetchClientPaymentsMessage;
use App\Payment\PaymentProcessor;
use App\Repository\ClientRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;

#[AsMessageHandler]
final readonly class FetchClientPaymentsMessageHandler
{
    public function __construct(
        private ClientRepository $clientRepository,
        private PaymentProcessor $paymentProcessor,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(FetchClientPaymentsMessage $message): void
    {
        $client = $this->clientRepository->find($message->clientId);

        if ($client === null || !$client->isActive()) {
            $this->logger->info('FetchClientPaymentsMessage: client {id} is gone or inactive, skipping', ['id' => $message->clientId]);

            return;
        }

        try {
            $added = $this->paymentProcessor->fetchMissingPayments($client, $message->attemptAutomaticCredit);
        } catch (HelloAssoRateLimitedException $exception) {
            // HelloAsso is throttling us: reschedule after the cool-down instead of
            // burning the retries immediately and dumping the message in "failed".
            $this->logger->warning('HelloAsso rate limited while fetching client {id}: retry in {wait}s', ['id' => $message->clientId, 'wait' => $exception->retryAfterSeconds]);

            throw new RecoverableMessageHandlingException($exception->getMessage(), 0, $exception, $exception->retryAfterSeconds * 1000 + random_int(0, 15_000));
        }

        if ($added > 0) {
            $this->logger->info('HelloAsso fetch: {count} new payment(s) for client {slug} (auto-credit: {auto})', [
                'count' => $added,
                'slug' => $client->getSlug(),
                'auto' => $message->attemptAutomaticCredit ? 'yes' : 'no',
            ]);
        }
    }
}
