<?php

namespace App\Controller\Dev;

use App\Deployment\DeploymentRunner;
use App\Deployment\PaySiteUpdater;
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
        private readonly PaySiteUpdater $paySiteUpdater,
    ) {
    }

    #[Route(path: '', name: 'show', methods: ['GET'])]
    public function show(Request $request): Response
    {
        return $this->render('dev/version/show.html.twig', [
            'status' => $this->versionChecker->check(forceRefresh: $request->query->getBoolean('refresh')),
            'paySite' => $this->paySiteUpdater->isConfigured() ? $this->paySiteUpdater->status() : null,
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
            'paySite' => $this->paySiteUpdater->isConfigured() ? $this->paySiteUpdater->status() : null,
            'deployResult' => $result,
        ]);
    }

    /**
     * Asks the payment site (another server) to update itself. It answers at
     * once and works in the background; the page then follows its progress.
     */
    #[Route(path: '/pay/deployer', name: 'deploy_pay', methods: ['POST'])]
    public function deployPay(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('deploy_pay', $request->request->get('_token'))) {
            return $this->redirectToRoute('dev_version_show');
        }

        try {
            $started = $this->paySiteUpdater->deploy();
            $this->addFlash($started ? 'success' : 'error', $started ? 'Mise à jour du site de paiement lancée.' : 'Une mise à jour du site de paiement est déjà en cours.');
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('dev_version_show');
    }
}
