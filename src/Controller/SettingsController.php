<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ChangeEmailType;
use App\Form\ChangePasswordType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/settings', name: 'settings_')]
#[IsGranted('ROLE_USER')]
class SettingsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    #[Route(path: '', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('settings/index.html.twig', [
            'emailForm' => $this->createForm(ChangeEmailType::class, ['email' => $this->getCurrentUser()->getEmail()]),
            'passwordForm' => $this->createForm(ChangePasswordType::class),
        ]);
    }

    #[Route(path: '/email', name: 'email', methods: ['POST'])]
    public function updateEmail(Request $request): Response
    {
        $user = $this->getCurrentUser();
        $form = $this->createForm(ChangeEmailType::class, ['email' => $user->getEmail()]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $newEmail = $form->getData()['email'];

            if ($newEmail !== $user->getEmail() && $this->userRepository->findOneBy(['email' => $newEmail]) !== null) {
                $form->get('email')->addError(new FormError('Un compte utilise déjà cette adresse e-mail.'));
            } else {
                $user->setEmail($newEmail);
                $this->entityManager->flush();
                $this->addFlash('success', 'Votre adresse e-mail a été mise à jour.');

                return $this->redirectToRoute('settings_index');
            }
        }

        return $this->render('settings/index.html.twig', [
            'emailForm' => $form,
            'passwordForm' => $this->createForm(ChangePasswordType::class),
        ]);
    }

    #[Route(path: '/password', name: 'password', methods: ['POST'])]
    public function updatePassword(Request $request): Response
    {
        $user = $this->getCurrentUser();
        $form = $this->createForm(ChangePasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword($this->passwordHasher->hashPassword($user, $form->getData()['plainPassword']));
            $this->entityManager->flush();
            $this->addFlash('success', 'Votre mot de passe a été mis à jour.');

            return $this->redirectToRoute('settings_index');
        }

        return $this->render('settings/index.html.twig', [
            'emailForm' => $this->createForm(ChangeEmailType::class, ['email' => $user->getEmail()]),
            'passwordForm' => $form,
        ]);
    }

    #[Route(path: '/theme', name: 'theme', methods: ['POST'])]
    public function updateTheme(Request $request): Response
    {
        $user = $this->getCurrentUser();
        $theme = $request->request->get('theme');

        if (\in_array($theme, [User::THEME_LIGHT, User::THEME_DARK], true)) {
            $user->setTheme($theme);
            $this->entityManager->flush();
        }

        return $this->redirectToRoute('settings_index');
    }

    private function getCurrentUser(): User
    {
        /** @var User $user */
        $user = $this->getUser();

        return $user;
    }
}
