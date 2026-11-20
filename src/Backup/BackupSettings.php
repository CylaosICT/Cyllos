<?php

namespace App\Backup;

use App\Repository\AppSettingRepository;

/**
 * Schedule of the automatic database backups, editable from the back-office.
 * The scheduler wakes the app:backup:auto command every 15 minutes; the
 * command decides whether a backup is actually due.
 */
class BackupSettings
{
    /** Allowed intervals, in hours => label. */
    public const INTERVALS = [
        6 => 'Toutes les 6 heures',
        12 => 'Toutes les 12 heures',
        24 => 'Tous les jours',
        72 => 'Tous les 3 jours',
        168 => 'Toutes les semaines',
    ];

    public function __construct(
        private readonly AppSettingRepository $settings,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->settings->get('backup.auto.enabled', '0') === '1';
    }

    public function intervalHours(): int
    {
        $hours = (int) $this->settings->get('backup.auto.interval_hours', '24');

        return isset(self::INTERVALS[$hours]) ? $hours : 24;
    }

    public function retention(): int
    {
        return max(1, min(60, (int) $this->settings->get('backup.auto.retention', '7')));
    }

    public function save(bool $enabled, int $intervalHours, int $retention): void
    {
        $this->settings->set('backup.auto.enabled', $enabled ? '1' : '0');
        $this->settings->set('backup.auto.interval_hours', (string) (isset(self::INTERVALS[$intervalHours]) ? $intervalHours : 24));
        $this->settings->set('backup.auto.retention', (string) max(1, min(60, $retention)));
    }
}
