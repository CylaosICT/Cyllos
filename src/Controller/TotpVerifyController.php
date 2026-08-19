<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\SecretEncryptor;
use App\Security\TotpService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * Second step of login for accounts with TOTP enabled. Only reachable with a
 * `totp_pending_user_id` session value, set by TotpLoginListener right after
 * a successful password check — this controller never re-checks the
 * password, it only turns that pending state into a real authenticated
 * session once the correct 6-digit code is supplied. Persisting the new
 * token into the session for subsequent requests is handled automatically
 * by Symfony's ContextListener once the token is in TokenStorage — no
 * manual session write needed here.
 */
class TotpVerifyController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly TotpService $totpService,
        private readonly SecretEncryptor $secretEncryptor,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    #[Route(path: '/verification-2fa', name: 'app_totp_verify', methods: ['GET', 'POST'])]
    public function verify(Request $request): Response
    {
        $userId = $request->getSession()->get('totp_pending_user_id');
        $user = $userId !== null ? $this->userRepository->find($userId) : null;

        if (!$user instanceof User || !$user->isTotpEnabled() || $user->getTotpSecretEncrypted() === null) {
            $request->getSession()->remove('totp_pending_user_id');

            return $this->redirectToRoute('app_login');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('totp_verify', $request->request->get('_token'))) {
                $this->addFlash('error', 'Jeton invalide, réessaie.');

                return $this->redirectToRoute('app_totp_verify');
            }

            $code = (string) $request->request->get('code', '');
            $secret = $this->secretEncryptor->decrypt($user->getTotpSecretEncrypted());

            if ($this->totpService->verify($secret, $code)) {
                $request->getSession()->remove('totp_pending_user_id');
                $request->getSession()->migrate(true);

                $this->tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

                return $this->redirectToRoute('app_home');
            }

            $this->addFlash('error', 'Code invalide.');
        }

        return $this->render('security/totp_verify.html.twig');
    }
}
