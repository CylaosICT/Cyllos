<?php

namespace App\Notification;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Sends plain-text operational emails (late payment, failure, success...) to a
 * client's configured mail recipient(s). Ported from MailService.java.
 */
class NotificationMailer
{
    private const RECIPIENT_DELIMITER = ',';

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly string $fromAddress,
    ) {
    }

    public function send(string $recipients, string $subject, string $body): void
    {
        $addresses = array_filter(array_map('trim', explode(self::RECIPIENT_DELIMITER, $recipients)));
        if ($addresses === []) {
            $this->logger->warning('No mail recipient configured, skipping notification "{subject}"', ['subject' => $subject]);

            return;
        }

        $email = (new Email())
            ->from($this->fromAddress)
            ->to(...$addresses)
            ->subject($subject)
            ->text($body);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('Error sending email: {message}', ['message' => $exception->getMessage()]);
        }
    }
}
