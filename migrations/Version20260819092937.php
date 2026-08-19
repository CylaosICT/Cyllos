<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260819092937 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add password-reset and TOTP 2FA columns to user. (The Client/ClientSetting columns from Version20260819092009 were re-detected here too since that migration had not run yet in this environment; that duplication is removed — see 092009 for those.)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD reset_token_hash VARCHAR(64) DEFAULT NULL, ADD reset_token_expires_at DATETIME DEFAULT NULL, ADD totp_secret_encrypted LONGTEXT DEFAULT NULL, ADD totp_enabled TINYINT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP reset_token_hash, DROP reset_token_expires_at, DROP totp_secret_encrypted, DROP totp_enabled');
    }
}
