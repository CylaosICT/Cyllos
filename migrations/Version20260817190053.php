<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260817190053 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE client_setting ADD notify_association_on_payment TINYINT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE hello_asso_config CHANGE form_type form_type VARCHAR(50) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE client_setting DROP notify_association_on_payment');
        $this->addSql('ALTER TABLE hello_asso_config CHANGE form_type form_type VARCHAR(50) DEFAULT \'PaymentForm\' NOT NULL');
    }
}
