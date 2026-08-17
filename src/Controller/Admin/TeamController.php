<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Form\AdminUserType;
use App\Form\ResetPasswordType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Manages global Cylaos team accounts (admins/developers/CEO), as opposed to
 * client-scoped accounts which are managed from a client's own profile page.
 *
 * Permission matrix:
 * - ROLE_ADMIN can create plain admin accounts, and edit/delete/reset the
 *   password of other plain admins, but can only *view* developer/CEO rows.
 * - ROLE_CEO can additionally grant ROLE_DEVELOPER when creating an account,
 *   manage developer accounts, and (de)activate admin accounts.
 * - Nobody can act on their own row here; self-service is done via /settings.
 */
#[Route(path: '/admin/equipe', name: 'admin_team_')]
#[IsGranted('ROLE_ADMIN')]
class TeamController extends AbstractController
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    #[Route(path: '', name: 'list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $page = $request->query->getInt('page', 1);

        return $this->render('admin/team/list.html.twig', [
            'pagination' => $this->userRepository->paginateGlobalTeam($page, self::PER_PAGE),
        ]);
    }

    #[Route(path: '/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $isCeo = $this->isGranted(User::ROLE_CEO);

        $form = $this->createForm(AdminUserType::class, null, ['show_developer_option' => $isCeo]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            if ($this->userRepository->findOneBy(['email' => $data['email']]) !== null) {
                $form->get('email')->addError(new FormError('Un utilisateur avec cet e-mail existe déjà.'));
            } else {
                $roles = [User::ROLE_ADMIN];
                if ($isCeo && ($data['developer'] ?? false)) {
                    $roles[] = User::ROLE_DEVELOPER;
                }

                $user = new User();
                $user->setEmail($data['email']);
                $user->setRoles($roles);
                $user->setPassword($this->passwordHasher->hashPassword($user, $data['plainPassword']));

                $this->entityManager->persist($user);
                $this->entityManager->flush();

                $this->addFlash('success', sprintf('Le compte "%s" a été créé.', $user->getEmail()));

                return $this->redirectToRoute('admin_team_list');
            }
        }

        return $this->render('admin/team/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route(path: '/{id}/mot-de-passe', requirements: ['id' => '\d+'], name: 'reset_password', methods: ['GET', 'POST'])]
    public function resetPassword(User $user, Request $request): Response
    {
        $this->assertGlobalTeamMember($user);
        $this->assertCanManage($user);

        if ($user === $this->getUser()) {
            $this->addFlash('error', 'Vous ne pouvez pas réinitialiser votre propre mot de passe ici : utilisez la page Réglages.');

            return $this->redirectToRoute('admin_team_list');
        }

        $form = $this->createForm(ResetPasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword($this->passwordHasher->hashPassword($user, $form->getData()['plainPassword']));
            $this->entityManager->flush();
            $this->addFlash('success', sprintf('Le mot de passe de "%s" a été réinitialisé.', $user->getEmail()));

            return $this->redirectToRoute('admin_team_list');
        }

        return $this->render('admin/team/reset_password.html.twig', [
            'targetUser' => $user,
            'form' => $form,
        ]);
    }

    #[Route(path: '/{id}/supprimer', requirements: ['id' => '\d+'], name: 'delete', methods: ['POST'])]
    public function delete(User $user, Request $request): Response
    {
        $this->assertGlobalTeamMember($user);
        $this->assertCanManage($user);

        if ($user === $this->getUser()) {
            $this->addFlash('error', 'Vous ne pouvez pas supprimer votre propre compte.');

            return $this->redirectToRoute('admin_team_list');
        }

        if ($this->isCsrfTokenValid('delete_team_user_' . $user->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($user);
            $this->entityManager->flush();
            $this->addFlash('success', sprintf('Le compte "%s" a été supprimé.', $user->getEmail()));
        }

        return $this->redirectToRoute('admin_team_list');
    }

    #[Route(path: '/{id}/statut', requirements: ['id' => '\d+'], name: 'toggle_active', methods: ['POST'])]
    #[IsGranted('ROLE_CEO')]
    public function toggleActive(User $user, Request $request): Response
    {
        $this->assertGlobalTeamMember($user);

        if ($user === $this->getUser()) {
            $this->addFlash('error', 'Vous ne pouvez pas désactiver votre propre compte.');

            return $this->redirectToRoute('admin_team_list');
        }

        if ($this->isCsrfTokenValid('toggle_active_' . $user->getId(), $request->request->get('_token'))) {
            $user->setActive(!$user->isActive());
            $this->entityManager->flush();
            $this->addFlash('success', sprintf('Le compte "%s" a été %s.', $user->getEmail(), $user->isActive() ? 'réactivé' : 'désactivé'));
        }

        return $this->redirectToRoute('admin_team_list');
    }

    private function assertGlobalTeamMember(User $user): void
    {
        if ($user->getClient() !== null) {
            throw $this->createNotFoundException();
        }
    }

    /**
     * A plain admin may only view developer/CEO accounts, not edit or delete
     * them — only a CEO can manage those.
     */
    private function assertCanManage(User $user): void
    {
        if (($user->isDeveloper() || $user->isCeo()) && !$this->isGranted(User::ROLE_CEO)) {
            throw $this->createAccessDeniedException('Seul le CEO peut gérer un compte développeur.');
        }
    }
}
