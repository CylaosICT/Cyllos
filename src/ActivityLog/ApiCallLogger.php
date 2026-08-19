<?php

namespace App\ActivityLog;

use App\Entity\ActivityLog;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Records a trace of every outgoing HelloAsso/Cyclos API call as an
 * ActivityLog row (action "api.helloasso" / "api.cyclos"), so a developer can
 * inspect exactly what was sent and received for a given integration issue.
 *
 * Callers are responsible for never passing credentials (passwords, client
 * secrets, bearer tokens) into $requestBody/$responseBody — this class does
 * not attempt to detect and scrub secrets after the fact, since that's
 * unreliable; it only enforces a size cap.
 */
class ApiCallLogger
{
    private const MAX_BODY_LENGTH = 4000;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function record(
        string $service,
        string $method,
        string $url,
        ?string $requestBody,
        int $statusCode,
        ?string $responseBody,
        ?string $summary = null,
    ): void {
        $log = new ActivityLog();
        $log->setAction('api.' . $service);
        $log->setSummary($summary ?? sprintf('%s %s', $method, $this->pathOnly($url)));
        $log->setApiService($service);
        $log->setApiMethod($method);
        $log->setApiUrl($url);
        $log->setApiStatusCode($statusCode);
        $log->setApiRequestBody($this->truncate($requestBody));
        $log->setApiResponseBody($this->truncate($responseBody));

        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }

    private function truncate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return \strlen($value) > self::MAX_BODY_LENGTH
            ? substr($value, 0, self::MAX_BODY_LENGTH) . "\n… (tronqué)"
            : $value;
    }

    private function pathOnly(string $url): string
    {
        return (string) parse_url($url, \PHP_URL_PATH);
    }
}
