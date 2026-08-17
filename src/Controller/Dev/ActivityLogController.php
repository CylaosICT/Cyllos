<?php

namespace App\Controller\Dev;

use App\Repository\ActivityLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/dev/journal', name: 'dev_log_')]
#[IsGranted('ROLE_DEVELOPER')]
class ActivityLogController extends AbstractController
{
    private const PER_PAGE = 60;

    public function __construct(
        private readonly ActivityLogRepository $activityLogRepository,
    ) {
    }

    #[Route(path: '', name: 'list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $page = max(1, $request->query->getInt('page', 1));

        return $this->render('dev/activity_log/list.html.twig', [
            'logs' => $this->activityLogRepository->findRecent(self::PER_PAGE, (self::PER_PAGE) * ($page - 1)),
            'page' => $page,
            'perPage' => self::PER_PAGE,
        ]);
    }
}
