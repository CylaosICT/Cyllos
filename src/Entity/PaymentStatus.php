<?php

namespace App\Entity;

enum PaymentStatus: string
{
    case Todo = 'todo';
    case TooHigh = 'too_high';
    case TooLate = 'too_late';
    case PreviewOk = 'preview_ok';
    case Success = 'success';
    case SuccessAuto = 'success_auto';
    case Fail = 'fail';
    case Waiting = 'waiting';
    case ManualCredit = 'manual_credit';

    public function label(): string
    {
        return match ($this) {
            self::Todo => 'À faire',
            self::TooHigh => 'Montant trop haut',
            self::TooLate => 'En retard',
            self::PreviewOk => 'Réactiver les paiements pour effectuer le paiement',
            self::Success => 'Succès',
            self::SuccessAuto => 'Succès (automatique)',
            self::Fail => 'Échec',
            self::Waiting => 'En attente',
            self::ManualCredit => 'Alimenté manuellement',
        };
    }

    public function isSuccessful(): bool
    {
        return $this === self::Success || $this === self::SuccessAuto || $this === self::ManualCredit;
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Success, self::SuccessAuto, self::PreviewOk, self::ManualCredit => 'badge--success',
            self::Fail, self::TooHigh => 'badge--fail',
            self::TooLate => 'badge--warning',
            self::Waiting => 'badge--waiting',
            self::Todo => 'badge--todo',
        };
    }

    /**
     * Statuses meaning the payment is resolved and money has moved (or is
     * recorded as having moved outside Cyllos) — never needs further action.
     *
     * @return PaymentStatus[]
     */
    public static function credited(): array
    {
        return [self::Success, self::SuccessAuto, self::ManualCredit];
    }

    /**
     * Statuses meaning the payment is not resolved and needs attention —
     * not yet credited, not a terminal failure.
     *
     * @return PaymentStatus[]
     */
    public static function toHandle(): array
    {
        return [self::Todo, self::TooHigh, self::TooLate, self::Waiting, self::PreviewOk];
    }
}
