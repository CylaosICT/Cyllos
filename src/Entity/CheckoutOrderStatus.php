<?php

namespace App\Entity;

/**
 * Lifecycle of a Stripe Checkout order (a top-up the payer started on the
 * payment site). "Paid" means Stripe confirmed the money; whether the Cyclos
 * account has been credited is tracked on the resulting Payment.
 */
enum CheckoutOrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Expired = 'expired';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Paid => 'Payée',
            self::Expired => 'Expirée',
            self::Failed => 'Échouée',
        };
    }
}
