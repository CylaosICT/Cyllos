<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260819153438 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Payment.payerEmail: the HelloAsso email captured once at creation, kept '
            . 'immutable unlike email (which gets overwritten on a successful credit that used '
            . 'an EmailAlias or the alternative-email fallback). Backfilled from the existing '
            . 'email column for prior rows — for any payment where that column was already '
            . 'overwritten before this migration, the true original HelloAsso email is not '
            . 'recoverable, so the current email value is the closest available approximation.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment ADD payer_email VARCHAR(255) DEFAULT NULL');
        $this->addSql('UPDATE payment SET payer_email = email WHERE payer_email IS NULL');
        $this->addSql('ALTER TABLE payment CHANGE payer_email payer_email VARCHAR(255) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE payment DROP payer_email');
    }
}
