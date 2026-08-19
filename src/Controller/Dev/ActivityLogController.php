<?php

namespace App\Controller\Dev;

use App\Entity\User;
use App\Form\ConfirmPasswordType;
use App\Repository\ActivityLogRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/dev/journal', name: 'dev_log_')]
#[IsGranted('ROLE_DEVELOPER')]
class ActivityLogController extends AbstractController
{
    private const PER_PAGE = 28;

    public function __construct(
        private readonly ActivityLogRepository $activityLogRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    #[Route(path: '', name: 'list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $page = $request->query->getInt('page', 1);
        $pagination = $this->activityLogRepository->paginate($page, self::PER_PAGE);

        return $this->render('dev/activity_log/list.html.twig', [
            'logs' => $pagination['items'],
            'pagination' => $pagination,
            'flushForm' => $this->createForm(ConfirmPasswordType::class)->createView(),
        ]);
    }

    /**
     * Wipes the activity log entirely. Restricted to ROLE_DEVELOPER/ROLE_CEO
     * (this whole controller already is), and additionally requires
     * re-entering the current account's password — a bulk-delete of the
     * audit trail is exactly the kind of action a stolen session shouldn't
     * be able to do silently.
     */
    #[Route(path: '/vider', name: 'flush', methods: ['POST'])]
    public function flush(Request $request): Response
    {
        $form = $this->createForm(ConfirmPasswordType::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'Mot de passe requis pour vider le journal.');

            return $this->redirectToRoute('dev_log_list');
        }

        /** @var User $user */
        $user = $this->getUser();

        if (!$this->passwordHasher->isPasswordValid($user, $form->getData()['password'])) {
            $this->addFlash('error', 'Mot de passe incorrect — le journal n\'a pas été vidé.');

            return $this->redirectToRoute('dev_log_list');
        }

        $deleted = $this->activityLogRepository->deleteAll();
        $this->addFlash('success', sprintf('%d entrée(s) supprimée(s) du journal.', $deleted));

        return $this->redirectToRoute('dev_log_list');
    }
}
