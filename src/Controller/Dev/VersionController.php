<?php

namespace App\Controller\Dev;

use App\Deployment\VersionChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/dev/version', name: 'dev_version_')]
#[IsGranted('ROLE_DEVELOPER')]
class VersionController extends AbstractController
{
    public function __construct(
        private readonly VersionChecker $versionChecker,
    ) {
    }

    #[Route(path: '', name: 'show', methods: ['GET'])]
    public function show(Request $request): Response
    {
        return $this->render('dev/version/show.html.twig', [
            'status' => $this->versionChecker->check(forceRefresh: $request->query->getBoolean('refresh')),
        ]);
    }
}
