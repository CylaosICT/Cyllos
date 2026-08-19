<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ForgotPasswordType;
use App\Form\ResetPasswordType;
use App\Notification\NotificationMailer;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Self-service "forgot password" flow, deliberately restricted to client
 * accounts (User::getClient() !== null). Admin/developer/CEO accounts must
 * always have their password reset by another admin/CEO via /admin/equipe —
 * allowing self-service reset on internal accounts would let anyone who
 * compromises a mailbox escalate straight into the admin panel.
 */
class PasswordResetController extends AbstractController
{
    private const TOKEN_TTL_MINUTES = 60;

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly NotificationMailer $mailer,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    #[Route(path: '/mot-de-passe-oublie', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function request(Request $request): Response
    {
        $form = $this->createForm(ForgotPasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user = $this->userRepository->findOneBy(['email' => $form->getData()['email']]);

            // Only ever act for client accounts, and only if active. The
            // flash message is identical either way, so an attacker can't
            // use this form to discover which emails have an account.
            if ($user !== null && $user->getClient() !== null && $user->isActive()) {
                $this->sendResetLink($user);
            }

            $this->addFlash('success', "Si un compte client existe avec cette adresse, un e-mail de réinitialisation a été envoyé.");

            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/forgot_password.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route(path: '/reinitialiser-mot-de-passe/{token}', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function reset(string $token, Request $request): Response
    {
        $user = $this->findUserByToken($token);

        if ($user === null) {
            $this->addFlash('error', 'Ce lien de réinitialisation est invalide ou a expiré.');

            return $this->redirectToRoute('app_forgot_password');
        }

        $form = $this->createForm(ResetPasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword($this->passwordHasher->hashPassword($user, $form->getData()['plainPassword']));
            $user->setResetToken(null, null);
            $this->entityManager->flush();

            $this->addFlash('success', 'Votre mot de passe a été réinitialisé, vous pouvez vous connecter.');

            return $this->redirectToRoute('app_login');
        }

        return $this->render('security/reset_password.html.twig', [
            'form' => $form,
            'token' => $token,
        ]);
    }

    private function sendResetLink(User $user): void
    {
        $rawToken = bin2hex(random_bytes(32));
        $user->setResetToken(
            hash('sha256', $rawToken),
            new \DateTimeImmutable('+' . self::TOKEN_TTL_MINUTES . ' minutes'),
        );
        $this->entityManager->flush();

        $url = $this->generateUrl('app_reset_password', ['token' => $rawToken], UrlGeneratorInterface::ABSOLUTE_URL);

        $this->mailer->send(
            $user->getEmail(),
            '[Cyllos] Réinitialisation de votre mot de passe',
            sprintf(
                "Une réinitialisation de mot de passe a été demandée pour ce compte.\n\n".
                "Cliquez sur le lien suivant pour choisir un nouveau mot de passe (valable %d minutes) :\n%s\n\n".
                "Si vous n'êtes pas à l'origine de cette demande, ignorez simplement cet e-mail.",
                self::TOKEN_TTL_MINUTES,
                $url,
            ),
        );
    }

    private function findUserByToken(string $token): ?User
    {
        $hash = hash('sha256', $token);
        $user = $this->userRepository->findOneBy(['resetTokenHash' => $hash]);

        if ($user === null || $user->getClient() === null) {
            return null;
        }

        if ($user->getResetTokenExpiresAt() === null || $user->getResetTokenExpiresAt() < new \DateTimeImmutable()) {
            return null;
        }

        return $user;
    }
}
