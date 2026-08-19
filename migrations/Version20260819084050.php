<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260819084050 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add API call trace columns to activity_log, plus accumulated schema drift (messenger index, user.theme).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE activity_log ADD api_service VARCHAR(20) DEFAULT NULL, ADD api_method VARCHAR(10) DEFAULT NULL, ADD api_url VARCHAR(500) DEFAULT NULL, ADD api_status_code INT DEFAULT NULL, ADD api_request_body LONGTEXT DEFAULT NULL, ADD api_response_body LONGTEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE user CHANGE theme theme VARCHAR(10) NOT NULL');
        $this->addSql('DROP INDEX IDX_75EA56E016BA31DB ON messenger_messages');
        $this->addSql('DROP INDEX IDX_75EA56E0FB7336F0 ON messenger_messages');
        $this->addSql('DROP INDEX IDX_75EA56E0E3BD61CE ON messenger_messages');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE activity_log DROP api_service, DROP api_method, DROP api_url, DROP api_status_code, DROP api_request_body, DROP api_response_body');
        $this->addSql('DROP INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages');
        $this->addSql('CREATE INDEX IDX_75EA56E016BA31DB ON messenger_messages (delivered_at)');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0 ON messenger_messages (queue_name)');
        $this->addSql('CREATE INDEX IDX_75EA56E0E3BD61CE ON messenger_messages (available_at)');
        $this->addSql('ALTER TABLE user CHANGE theme theme VARCHAR(10) DEFAULT \'light\' NOT NULL');
    }
}
