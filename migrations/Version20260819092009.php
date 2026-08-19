<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260819092009 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add Client.contactEmail and split ClientSetting.notifyAssociationOnPayment into notifySuccessOnPayment/notifyFailureOnPayment.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE client ADD contact_email VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE client_setting ADD notify_failure_on_payment TINYINT NOT NULL, CHANGE notify_association_on_payment notify_success_on_payment TINYINT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE client DROP contact_email');
        $this->addSql('ALTER TABLE client_setting ADD notify_association_on_payment TINYINT NOT NULL, DROP notify_success_on_payment, DROP notify_failure_on_payment');
    }
}
