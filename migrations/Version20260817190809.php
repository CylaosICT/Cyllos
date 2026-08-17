<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260817190809 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE activity_log (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, actor_email VARCHAR(255) DEFAULT NULL, action VARCHAR(100) NOT NULL, summary VARCHAR(255) DEFAULT NULL, context LONGTEXT DEFAULT NULL, ip_address VARCHAR(45) DEFAULT NULL, INDEX IDX_FD06F6478B8E8428 (created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE client_setting CHANGE notify_association_on_payment notify_association_on_payment TINYINT NOT NULL');
        $this->addSql("ALTER TABLE user ADD theme VARCHAR(10) NOT NULL DEFAULT 'light'");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE activity_log');
        $this->addSql('ALTER TABLE client_setting CHANGE notify_association_on_payment notify_association_on_payment TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE user DROP theme');
    }
}
