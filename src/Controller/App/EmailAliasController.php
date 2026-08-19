<?php

namespace App\Controller\App;

use App\Entity\EmailAlias;
use App\Entity\User;
use App\Form\EmailAliasType;
use App\Repository\EmailAliasRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Lets a client define their own EmailAlias correction rules, self-service —
 * so they no longer need to email Cylaos to ask for a payer email to be
 * redirected to the right Cyclos account. The client is always taken from
 * the logged-in user, never from a route parameter, so a client can only
 * ever see or touch its own rules.
 */
#[Route(path: '/app/regles-email', name: 'app_email_alias_')]
#[IsGranted('ROLE_CLIENT')]
class EmailAliasController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EmailAliasRepository $emailAliasRepository,
    ) {
    }

    #[Route(path: '', name: 'list', methods: ['GET'])]
    public function list(): Response
    {
        $client = $this->getCurrentUser()->getClient();

        return $this->render('app/email_alias/list.html.twig', [
            'client' => $client,
            'emailAliases' => $this->emailAliasRepository->findAllForClient($client),
        ]);
    }

    #[Route(path: '/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $client = $this->getCurrentUser()->getClient();

        $alias = new EmailAlias();
        $alias->setClient($client);

        $prefillSourceEmail = $request->query->get('sourceEmail');
        if (\is_string($prefillSourceEmail) && $prefillSourceEmail !== '') {
            $alias->setSourceEmail($prefillSourceEmail);
        }

        $form = $this->createForm(EmailAliasType::class, $alias);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($this->emailAliasRepository->findOneByClientAndSourceEmail($client, $alias->getSourceEmail()) !== null) {
                $form->get('sourceEmail')->addError(new FormError('Une règle existe déjà pour cet e-mail HelloAsso.'));
            } else {
                $this->entityManager->persist($alias);
                $this->entityManager->flush();

                $this->addFlash('success', sprintf('Vos paiements de "%s" seront désormais crédités sur "%s".', $alias->getSourceEmail(), $alias->getTargetEmail()));

                return $this->redirectToRoute('app_email_alias_list');
            }
        }

        return $this->render('app/email_alias/new.html.twig', [
            'client' => $client,
            'form' => $form,
        ]);
    }

    #[Route(path: '/{id}/supprimer', requirements: ['id' => '\d+'], name: 'delete', methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $client = $this->getCurrentUser()->getClient();

        $alias = $this->emailAliasRepository->find($id);
        if ($alias === null || $alias->getClient() !== $client) {
            throw $this->createNotFoundException('Règle introuvable.');
        }

        if ($this->isCsrfTokenValid('delete_email_alias_' . $alias->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($alias);
            $this->entityManager->flush();
            $this->addFlash('success', sprintf('La règle pour "%s" a été supprimée.', $alias->getSourceEmail()));
        }

        return $this->redirectToRoute('app_email_alias_list');
    }

    private function getCurrentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
