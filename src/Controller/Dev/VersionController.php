<?php

namespace App\Controller\Dev;

use App\Deployment\DeploymentRunner;
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
        private readonly DeploymentRunner $deploymentRunner,
    ) {
    }

    #[Route(path: '', name: 'show', methods: ['GET'])]
    public function show(Request $request): Response
    {
        return $this->render('dev/version/show.html.twig', [
            'status' => $this->versionChecker->check(forceRefresh: $request->query->getBoolean('refresh')),
        ]);
    }

    /**
     * Runs the whitelisted deploy sequence synchronously and re-renders the
     * page with the result — see DeploymentRunner for why it's a
     * deliberately narrow, non-arbitrary set of commands.
     */
    #[Route(path: '/deployer', name: 'deploy', methods: ['POST'])]
    #[IsGranted('ROLE_DEVELOPER')]
    public function deploy(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('deploy_app', $request->request->get('_token'))) {
            return $this->redirectToRoute('dev_version_show');
        }

        $result = $this->deploymentRunner->run();

        return $this->render('dev/version/show.html.twig', [
            'status' => $this->versionChecker->check(forceRefresh: true),
            'deployResult' => $result,
        ]);
    }
}
