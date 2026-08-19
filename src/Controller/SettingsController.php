<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ChangeEmailType;
use App\Form\ChangePasswordType;
use App\Form\ClientNotificationPreferencesType;
use App\Form\ConfirmPasswordType;
use App\Repository\UserRepository;
use App\Security\SecretEncryptor;
use App\Security\TotpService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/settings', name: 'settings_')]
#[IsGranted('ROLE_USER')]
class SettingsController extends AbstractController
{
    private const TOTP_SETUP_SESSION_KEY = 'totp_setup_secret';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly TotpService $totpService,
        private readonly SecretEncryptor $secretEncryptor,
    ) {
    }

    #[Route(path: '', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('settings/index.html.twig', $this->settingsViewData());
    }

    #[Route(path: '/notifications', name: 'notifications', methods: ['POST'])]
    public function notifications(Request $request): Response
    {
        $client = $this->getCurrentUser()->getClient();
        if ($client === null || $client->getSetting() === null) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(ClientNotificationPreferencesType::class, $client->getSetting());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();
            $this->addFlash('success', 'Vos préférences de notification ont été mises à jour.');

            return $this->redirectToRoute('settings_index');
        }

        return $this->render('settings/index.html.twig', $this->settingsViewData(notificationsForm: $form));
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

        return $this->render('settings/index.html.twig', $this->settingsViewData(emailForm: $form));
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

        return $this->render('settings/index.html.twig', $this->settingsViewData(passwordForm: $form));
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsViewData(
        ?FormInterface $emailForm = null,
        ?FormInterface $passwordForm = null,
        ?FormInterface $notificationsForm = null,
    ): array {
        $user = $this->getCurrentUser();
        $client = $user->getClient();

        return [
            'emailForm' => $emailForm ?? $this->createForm(ChangeEmailType::class, ['email' => $user->getEmail()]),
            'passwordForm' => $passwordForm ?? $this->createForm(ChangePasswordType::class),
            'confirmPasswordForm' => $this->createForm(ConfirmPasswordType::class),
            'notificationsForm' => $notificationsForm
                ?? ($client !== null && $client->getSetting() !== null
                    ? $this->createForm(ClientNotificationPreferencesType::class, $client->getSetting())
                    : null),
        ];
    }

    #[Route(path: '/2fa/activer', name: 'totp_start', methods: ['POST'])]
    public function totpStart(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('totp_start', $request->request->get('_token'))) {
            return $this->redirectToRoute('settings_index');
        }

        $secret = $this->totpService->generateSecret();
        $request->getSession()->set(self::TOTP_SETUP_SESSION_KEY, $secret);

        return $this->render('settings/totp_setup.html.twig', [
            'secret' => $secret,
            'provisioningUri' => $this->totpService->getProvisioningUri($secret, $this->getCurrentUser()->getEmail()),
        ]);
    }

    #[Route(path: '/2fa/confirmer', name: 'totp_confirm', methods: ['POST'])]
    public function totpConfirm(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('totp_confirm', $request->request->get('_token'))) {
            return $this->redirectToRoute('settings_index');
        }

        $secret = $request->getSession()->get(self::TOTP_SETUP_SESSION_KEY);
        $code = (string) $request->request->get('code', '');

        if (!\is_string($secret) || !$this->totpService->verify($secret, $code)) {
            $this->addFlash('error', 'Code invalide — la double authentification n\'a pas été activée.');

            return $this->redirectToRoute('settings_index');
        }

        $user = $this->getCurrentUser();
        $user->setTotpSecretEncrypted($this->secretEncryptor->encrypt($secret));
        $user->setTotpEnabled(true);
        $this->entityManager->flush();

        $request->getSession()->remove(self::TOTP_SETUP_SESSION_KEY);
        $this->addFlash('success', 'La double authentification est activée sur ton compte.');

        return $this->redirectToRoute('settings_index');
    }

    #[Route(path: '/2fa/desactiver', name: 'totp_disable', methods: ['POST'])]
    public function totpDisable(Request $request): Response
    {
        $user = $this->getCurrentUser();
        $form = $this->createForm(ConfirmPasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->passwordHasher->isPasswordValid($user, $form->getData()['password'])) {
            $user->setTotpEnabled(false);
            $user->setTotpSecretEncrypted(null);
            $this->entityManager->flush();
            $this->addFlash('success', 'La double authentification a été désactivée.');
        } else {
            $this->addFlash('error', 'Mot de passe incorrect — la double authentification reste activée.');
        }

        return $this->redirectToRoute('settings_index');
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
