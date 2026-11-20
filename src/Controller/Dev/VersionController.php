<?php

namespace App\Controller\Dev;

use App\Deployment\PaySiteUpdater;
use App\Deployment\UpdateLauncher;
use App\Deployment\VersionChecker;
use App\Repository\UpdateRunRepository;
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
        private readonly PaySiteUpdater $paySiteUpdater,
        private readonly UpdateLauncher $launcher,
        private readonly UpdateRunRepository $runs,
    ) {
    }

    #[Route(path: '', name: 'show', methods: ['GET'])]
    public function show(Request $request): Response
    {
        return $this->render('dev/version/show.html.twig', [
            'status' => $this->versionChecker->check(forceRefresh: $request->query->getBoolean('refresh')),
            'paySite' => $this->paySiteUpdater->isConfigured() ? $this->paySiteUpdater->status() : null,
            'recentRuns' => $this->runs->findRecent(5),
            'runningRun' => $this->runs->findRunning(),
        ]);
    }

    /**
     * Records the update and starts it in the background; the browser is sent
     * to the run page, which shows the deployment as a live terminal.
     */
    #[Route(path: '/deployer', name: 'deploy', methods: ['POST'])]
    public function deploy(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('deploy_app', $request->request->get('_token'))) {
            return $this->redirectToRoute('dev_version_show');
        }

        $run = $this->launcher->startApp($this->getUser()?->getUserIdentifier());
        if ($run === null) {
            $this->addFlash('error', 'Une mise à jour de Cyllos est déjà en cours.');
            $running = $this->runs->findRunning();

            return $running !== null
                ? $this->redirectToRoute('dev_update_show', ['id' => $running->getId()])
                : $this->redirectToRoute('dev_version_show');
        }

        return $this->redirectToRoute('dev_update_show', ['id' => $run->getId()]);
    }

    /**
     * Asks the payment site (another server) to update itself. It works in the
     * background; the run page then follows its progress.
     */
    #[Route(path: '/pay/deployer', name: 'deploy_pay', methods: ['POST'])]
    public function deployPay(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('deploy_pay', $request->request->get('_token'))) {
            return $this->redirectToRoute('dev_version_show');
        }

        try {
            $run = $this->launcher->startPay($this->getUser()?->getUserIdentifier());
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('dev_version_show');
        }

        if ($run === null) {
            $this->addFlash('error', 'Une mise à jour du site de paiement est déjà en cours.');

            return $this->redirectToRoute('dev_version_show');
        }

        return $this->redirectToRoute('dev_update_show', ['id' => $run->getId()]);
    }
}
