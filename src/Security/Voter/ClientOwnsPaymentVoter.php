<?php

namespace App\Security\Voter;

use App\Entity\Payment;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Ensures a ROLE_CLIENT user can only act on payments belonging to their own
 * client, preventing cross-tenant data access in the multi-tenant app.
 *
 * @extends Voter<'CLIENT_OWNS_PAYMENT', Payment>
 */
class ClientOwnsPaymentVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === 'CLIENT_OWNS_PAYMENT' && $subject instanceof Payment;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if (\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        /** @var Payment $payment */
        $payment = $subject;

        return $user->getClient() !== null && $user->getClient() === $payment->getClient();
    }
}
