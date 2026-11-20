<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Link outbound API call traces to a client (activity_log.client_id) for the per-client connection health.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE activity_log ADD client_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_activity_log_client_service ON activity_log (client_id, api_service, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_activity_log_client_service ON activity_log');
        $this->addSql('ALTER TABLE activity_log DROP client_id');
    }
}
