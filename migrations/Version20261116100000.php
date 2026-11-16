<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stripe support, back-office side:
 *  - client.payment_provider ("helloasso" | "stripe"), every existing client
 *    stays on HelloAsso;
 *  - stripe_config: per-client Stripe credentials (encrypted) and amount limits;
 *  - client.contact_email is dropped: the single contact address is now
 *    client_setting.mail_recipient (the "Réglages" e-mail).
 */
final class Version20261116100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add client.payment_provider and stripe_config; drop client.contact_email (replaced by client_setting.mail_recipient).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE client ADD payment_provider VARCHAR(20) NOT NULL DEFAULT 'helloasso'");
        $this->addSql('ALTER TABLE client ALTER payment_provider DROP DEFAULT');
        $this->addSql('ALTER TABLE client DROP contact_email');

        $this->addSql('CREATE TABLE stripe_config (id INT AUTO_INCREMENT NOT NULL, active TINYINT NOT NULL, secret_key_encrypted LONGTEXT NOT NULL, webhook_secret_encrypted LONGTEXT NOT NULL, secret_key_hint VARCHAR(40) NOT NULL, live_mode TINYINT NOT NULL, min_amount INT NOT NULL, max_amount INT NOT NULL, client_id INT NOT NULL, UNIQUE INDEX UNIQ_7CC7524019EB6921 (client_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE stripe_config ADD CONSTRAINT FK_7CC7524019EB6921 FOREIGN KEY (client_id) REFERENCES client (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stripe_config DROP FOREIGN KEY FK_7CC7524019EB6921');
        $this->addSql('DROP TABLE stripe_config');

        $this->addSql('ALTER TABLE client ADD contact_email VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE client DROP payment_provider');
    }
}
