<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Enforces TOTP as a second step for accounts that opted into it
 * (User::isTotpEnabled()). By the time LoginSuccessEvent fires, the
 * authenticator has already put a fully-authenticated token in storage — so
 * a user with TOTP enabled is, for a brief moment, actually logged in. This
 * listener immediately clears that token again and redirects to the TOTP
 * challenge instead, storing only the user's id (not a token) in the
 * session under `totp_pending_user_id`; TotpVerifyController is the only
 * place that turns that pending id back into a real authenticated session,
 * once the correct code is supplied.
 */
class TotpLoginListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [LoginSuccessEvent::class => ['onLoginSuccess', 100]];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User || !$user->isTotpEnabled()) {
            return;
        }

        $request = $event->getRequest();
        $request->getSession()->set('totp_pending_user_id', $user->getId());

        $this->tokenStorage->setToken(null);

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_totp_verify')));
    }
}
