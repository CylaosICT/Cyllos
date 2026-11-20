<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261120121200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE app_setting (name VARCHAR(100) NOT NULL, value LONGTEXT NOT NULL, PRIMARY KEY (name)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE database_backup (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, filename VARCHAR(255) NOT NULL, type VARCHAR(20) NOT NULL, size_bytes INT NOT NULL, created_by VARCHAR(255) DEFAULT NULL, INDEX IDX_FBEE83648B8E8428 (created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE update_run (id INT AUTO_INCREMENT NOT NULL, started_at DATETIME NOT NULL, finished_at DATETIME DEFAULT NULL, status VARCHAR(20) NOT NULL, from_commit VARCHAR(40) DEFAULT NULL, to_commit VARCHAR(40) DEFAULT NULL, commits JSON DEFAULT NULL, code_backup_file VARCHAR(255) DEFAULT NULL, code_backup_size INT DEFAULT NULL, target VARCHAR(10) NOT NULL, triggered_by VARCHAR(255) DEFAULT NULL, database_backup_id INT DEFAULT NULL, INDEX IDX_8711D9A37E83A2C0 (database_backup_id), INDEX IDX_8711D9A3D46F4E3 (started_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE update_run ADD CONSTRAINT FK_8711D9A37E83A2C0 FOREIGN KEY (database_backup_id) REFERENCES database_backup (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE update_run DROP FOREIGN KEY FK_8711D9A37E83A2C0');
        $this->addSql('DROP TABLE app_setting');
        $this->addSql('DROP TABLE database_backup');
        $this->addSql('DROP TABLE update_run');
    }
}
