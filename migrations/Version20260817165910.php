<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260817165910 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE client (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, slug VARCHAR(100) NOT NULL, active TINYINT NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_C7440455989D9B62 (slug), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE client_setting (id INT AUTO_INCREMENT NOT NULL, payment_cyclos_enabled TINYINT NOT NULL, payment_automatic_enabled TINYINT NOT NULL, mail_recipient VARCHAR(255) NOT NULL, client_id INT NOT NULL, UNIQUE INDEX UNIQ_CD49FCE519EB6921 (client_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE cyclos_config (id INT AUTO_INCREMENT NOT NULL, base_url VARCHAR(255) NOT NULL, technical_user_id VARCHAR(100) NOT NULL, password_encrypted LONGTEXT NOT NULL, group_pro_internal VARCHAR(255) NOT NULL, groups_part_internal VARCHAR(255) NOT NULL, emission_pro_internal VARCHAR(255) NOT NULL, emission_part_internal VARCHAR(255) NOT NULL, client_id INT NOT NULL, UNIQUE INDEX UNIQ_1693903719EB6921 (client_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE hello_asso_config (id INT AUTO_INCREMENT NOT NULL, api_url VARCHAR(255) NOT NULL, hello_asso_client_id VARCHAR(255) NOT NULL, client_secret_encrypted LONGTEXT NOT NULL, organization_slug VARCHAR(255) NOT NULL, form_slug VARCHAR(255) NOT NULL, max_amount INT NOT NULL, extra_mail_field_name VARCHAR(255) DEFAULT NULL, fetch_nb_days INT NOT NULL, client_id INT NOT NULL, UNIQUE INDEX UNIQ_2599AC4C19EB6921 (client_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE payment (id INT AUTO_INCREMENT NOT NULL, hello_asso_payment_id INT NOT NULL, payment_date DATETIME NOT NULL, amount DOUBLE PRECISION NOT NULL, payer_first_name VARCHAR(255) NOT NULL, payer_last_name VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL, insertion_date DATETIME NOT NULL, status VARCHAR(20) NOT NULL, error TEXT DEFAULT NULL, client_id INT NOT NULL, INDEX IDX_6D28840D19EB6921 (client_id), INDEX IDX_6D28840D7B00651C (status), UNIQUE INDEX client_helloasso_payment_unique (client_id, hello_asso_payment_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user (id INT AUTO_INCREMENT NOT NULL, email VARCHAR(255) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, active TINYINT NOT NULL, client_id INT DEFAULT NULL, INDEX IDX_8D93D64919EB6921 (client_id), UNIQUE INDEX user_email_unique (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0 (queue_name), INDEX IDX_75EA56E0E3BD61CE (available_at), INDEX IDX_75EA56E016BA31DB (delivered_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE client_setting ADD CONSTRAINT FK_CD49FCE519EB6921 FOREIGN KEY (client_id) REFERENCES client (id)');
        $this->addSql('ALTER TABLE cyclos_config ADD CONSTRAINT FK_1693903719EB6921 FOREIGN KEY (client_id) REFERENCES client (id)');
        $this->addSql('ALTER TABLE hello_asso_config ADD CONSTRAINT FK_2599AC4C19EB6921 FOREIGN KEY (client_id) REFERENCES client (id)');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840D19EB6921 FOREIGN KEY (client_id) REFERENCES client (id)');
        $this->addSql('ALTER TABLE user ADD CONSTRAINT FK_8D93D64919EB6921 FOREIGN KEY (client_id) REFERENCES client (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE client_setting DROP FOREIGN KEY FK_CD49FCE519EB6921');
        $this->addSql('ALTER TABLE cyclos_config DROP FOREIGN KEY FK_1693903719EB6921');
        $this->addSql('ALTER TABLE hello_asso_config DROP FOREIGN KEY FK_2599AC4C19EB6921');
        $this->addSql('ALTER TABLE payment DROP FOREIGN KEY FK_6D28840D19EB6921');
        $this->addSql('ALTER TABLE user DROP FOREIGN KEY FK_8D93D64919EB6921');
        $this->addSql('DROP TABLE client');
        $this->addSql('DROP TABLE client_setting');
        $this->addSql('DROP TABLE cyclos_config');
        $this->addSql('DROP TABLE hello_asso_config');
        $this->addSql('DROP TABLE payment');
        $this->addSql('DROP TABLE user');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
