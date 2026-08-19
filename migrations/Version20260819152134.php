<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260819152134 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add email_alias table: per-client HelloAsso payer email -> Cyclos account email correction rules.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE email_alias (id INT AUTO_INCREMENT NOT NULL, source_email VARCHAR(255) NOT NULL, target_email VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, client_id INT NOT NULL, INDEX IDX_7C212A1B19EB6921 (client_id), UNIQUE INDEX email_alias_client_source_unique (client_id, source_email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE email_alias ADD CONSTRAINT FK_7C212A1B19EB6921 FOREIGN KEY (client_id) REFERENCES client (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE email_alias DROP FOREIGN KEY FK_7C212A1B19EB6921');
        $this->addSql('DROP TABLE email_alias');
    }
}
