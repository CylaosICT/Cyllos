<?php

namespace App\Controller\Admin;

use App\Entity\PaymentStatus;
use App\Monitoring\QueueStatus;
use App\Repository\ActivityLogRepository;
use App\Repository\ClientRepository;
use App\Repository\PaymentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/admin/tableau-de-bord', name: 'admin_dashboard', methods: ['GET'])]
#[IsGranted('ROLE_ADMIN')]
class DashboardController extends AbstractController
{
    private const STATS_WINDOW_DAYS = 30;
    private const QUIET_CLIENT_HOURS = 48;
    private const STUCK_PAYMENT_AGE = '-15 minutes';
    private const LEGACY_WEBHOOK_WINDOW_DAYS = 30;

    public function __construct(
        private readonly PaymentRepository $paymentRepository,
        private readonly ClientRepository $clientRepository,
        private readonly ActivityLogRepository $activityLogRepository,
        private readonly QueueStatus $queueStatus,
    ) {
    }

    public function __invoke(): Response
    {
        $since = new \DateTimeImmutable(\sprintf('-%d days', self::STATS_WINDOW_DAYS));
        $counts = $this->paymentRepository->countByStatusSince($since);
        $amountsByStatus = $this->paymentRepository->sumAmountByStatusSince($since);

        $sum = static fn (PaymentStatus ...$statuses): int => array_sum(
            array_map(static fn (PaymentStatus $s) => $counts[$s->value] ?? 0, $statuses),
        );
        $sumAmount = static fn (PaymentStatus ...$statuses): float => array_sum(
            array_map(static fn (PaymentStatus $s) => $amountsByStatus[$s->value] ?? 0.0, $statuses),
        );

        $total = array_sum($counts);
        $credited = $sum(...PaymentStatus::credited());
        $failed = $sum(PaymentStatus::Fail);
        $toHandle = $sum(...PaymentStatus::toHandle());

        // Ordered breakdown for the distribution bar (only non-zero slices).
        $breakdown = [];
        foreach (PaymentStatus::cases() as $status) {
            if (($counts[$status->value] ?? 0) > 0) {
                $breakdown[] = ['status' => $status, 'count' => $counts[$status->value]];
            }
        }

        return $this->render('admin/dashboard.html.twig', [
            'windowDays' => self::STATS_WINDOW_DAYS,
            'total' => $total,
            'credited' => $credited,
            'failed' => $failed,
            'toHandle' => $toHandle,
            'failRate' => $total > 0 ? round($failed / $total * 100, 1) : 0.0,
            'amounts' => [
                'credited' => $sumAmount(...PaymentStatus::credited()),
                'failed' => $sumAmount(PaymentStatus::Fail),
                'toHandle' => $sumAmount(...PaymentStatus::toHandle()),
            ],
            'breakdown' => $breakdown,
            'perClient' => $this->buildPerClientRows($since),
            'queue' => [
                'pending' => $this->queueStatus->pending(),
                'failed' => $this->queueStatus->failed(),
                'oldestPendingSeconds' => $this->queueStatus->oldestPendingSeconds(),
            ],
            'failedMessages' => $this->queueStatus->failedMessages(),
            'apiHealth' => [
                'helloasso' => $this->activityLogRepository->lastApiCall('helloasso'),
                'cyclos' => $this->activityLogRepository->lastApiCall('cyclos'),
            ],
            'legacyWebhookClients' => $this->clientRepository->findWithRecentLegacyWebhook(
                new \DateTimeImmutable(\sprintf('-%d days', self::LEGACY_WEBHOOK_WINDOW_DAYS)),
            ),
            'recentActivity' => $this->activityLogRepository->findRecentAudit(8),
            'stuckAutomaticPayments' => $this->paymentRepository->countStuckAutomaticTodoPayments(new \DateTimeImmutable(self::STUCK_PAYMENT_AGE)),
            'activeClients' => $this->clientRepository->countActive(),
            'quietClients' => $this->clientRepository->findActiveQuietSince(new \DateTimeImmutable(\sprintf('-%d hours', self::QUIET_CLIENT_HOURS))),
            'quietClientHours' => self::QUIET_CLIENT_HOURS,
            'recentFailures' => $this->paymentRepository->findRecentByStatus(PaymentStatus::Fail, 5),
        ]);
    }

    /**
     * Per-client aggregates for the window, with the last-ever payment date
     * merged in from a separate query.
     *
     * @return list<array<string, mixed>>
     */
    private function buildPerClientRows(\DateTimeImmutable $since): array
    {
        $lastEver = $this->paymentRepository->lastPaymentAtByClient();

        return array_map(
            static function (array $row) use ($lastEver): array {
                $row['lastPaymentAt'] = $lastEver[$row['clientId']] ?? null;

                return $row;
            },
            $this->paymentRepository->perClientStatsSince($since),
        );
    }
}
