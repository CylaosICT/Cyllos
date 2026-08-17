<?php

namespace App\ActivityLog;

use App\Entity\ActivityLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Logs authentication events (outside of Doctrine's flush cycle, so these can
 * be persisted directly).
 */
class SecurityActivityLogSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RequestStack $requestStack,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
            LoginFailureEvent::class => 'onLoginFailure',
            LogoutEvent::class => 'onLogout',
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        $this->record('user.login_success', $user instanceof User ? $user->getEmail() : null, $user instanceof User ? $user->getEmail() : null);
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $attemptedEmail = $event->getRequest()->request->get('_username');
        $this->record('user.login_failed', null, \is_string($attemptedEmail) ? $attemptedEmail : null);
    }

    public function onLogout(LogoutEvent $event): void
    {
        $user = $event->getToken()?->getUser();
        $this->record('user.logout', $user instanceof User ? $user->getEmail() : null, $user instanceof User ? $user->getEmail() : null);
    }

    private function record(string $action, ?string $actorEmail, ?string $summary): void
    {
        $log = new ActivityLog();
        $log->setAction($action);
        $log->setActorEmail($actorEmail);
        $log->setSummary($summary);
        $log->setIpAddress($this->requestStack->getCurrentRequest()?->getClientIp());

        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }
}
