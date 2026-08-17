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
        };
    }

    public function isSuccessful(): bool
    {
        return $this === self::Success || $this === self::SuccessAuto;
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Success, self::SuccessAuto, self::PreviewOk => 'badge--success',
            self::Fail, self::TooHigh => 'badge--fail',
            self::TooLate => 'badge--warning',
            self::Waiting => 'badge--waiting',
            self::Todo => 'badge--todo',
        };
    }
}
