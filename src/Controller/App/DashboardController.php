<?php

namespace App\Controller\App;

use App\Entity\PaymentStatus;
use App\Entity\User;
use App\Repository\EmailAliasRepository;
use App\Repository\PaymentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Client-facing dashboard: the same figures as the admin one but scoped to the
 * single client the logged-in user belongs to (never a route parameter), and
 * trimmed to what a client cares about — their own payment volume over the
 * window, the last failures, the last few payments and their e-mail rules.
 */
#[Route(path: '/app/tableau-de-bord', name: 'app_dashboard', methods: ['GET'])]
#[IsGranted('ROLE_CLIENT')]
class DashboardController extends AbstractController
{
    private const STATS_WINDOW_DAYS = 30;
    private const RECENT_PAYMENTS = 8;
    private const RECENT_FAILURES = 5;
    private const RECENT_RULES = 5;

    public function __construct(
        private readonly PaymentRepository $paymentRepository,
        private readonly EmailAliasRepository $emailAliasRepository,
    ) {
    }

    public function __invoke(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $client = $user->getClient();

        $since = new \DateTimeImmutable(\sprintf('-%d days', self::STATS_WINDOW_DAYS));
        $counts = $this->paymentRepository->countByStatusSince($since, $client);
        $amounts = $this->paymentRepository->sumAmountByStatusSince($since, $client);

        $sum = static fn (PaymentStatus ...$statuses): int => array_sum(
            array_map(static fn (PaymentStatus $s) => $counts[$s->value] ?? 0, $statuses),
        );
        $sumAmount = static fn (PaymentStatus ...$statuses): float => array_sum(
            array_map(static fn (PaymentStatus $s) => $amounts[$s->value] ?? 0.0, $statuses),
        );

        $total = array_sum($counts);
        $failed = $sum(PaymentStatus::Fail);
        $toHandle = $sum(...PaymentStatus::toHandle());

        return $this->render('app/dashboard.html.twig', [
            'client' => $client,
            'windowDays' => self::STATS_WINDOW_DAYS,
            'total' => $total,
            'credited' => $sum(...PaymentStatus::credited()),
            'failed' => $failed,
            'toHandle' => $toHandle,
            'failRate' => $total > 0 ? round($failed / $total * 100, 1) : 0.0,
            'amounts' => [
                'credited' => $sumAmount(...PaymentStatus::credited()),
                'failed' => $sumAmount(PaymentStatus::Fail),
                'toHandle' => $sumAmount(...PaymentStatus::toHandle()),
            ],
            'recentPayments' => $this->paymentRepository->findRecentForClient($client, self::RECENT_PAYMENTS),
            'recentFailures' => $this->paymentRepository->findRecentByStatus(PaymentStatus::Fail, self::RECENT_FAILURES, $client),
            'recentRules' => \array_slice($this->emailAliasRepository->findAllForClient($client), 0, self::RECENT_RULES),
        ]);
    }
}
