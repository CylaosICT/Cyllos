<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Stripe payments, processing side:
 *  - checkout_order: a top-up started on the payment site (e-mail of the Cyclos
 *    account, amount, Stripe Checkout Session);
 *  - payment is generalised: provider (every existing row is "helloasso"),
 *    reference + checkout_order for Stripe payments, and the HelloAsso-only
 *    columns (hello_asso_payment_id, hello_asso_config_id) become nullable.
 */
final class Version20261116110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add checkout_order; generalise payment (provider, reference, checkout_order, nullable HelloAsso columns).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE checkout_order (id INT AUTO_INCREMENT NOT NULL, reference VARCHAR(20) NOT NULL, email VARCHAR(255) NOT NULL, amount_cents INT NOT NULL, status VARCHAR(20) NOT NULL, stripe_session_id VARCHAR(255) DEFAULT NULL, stripe_payment_intent_id VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, paid_at DATETIME DEFAULT NULL, client_id INT NOT NULL, INDEX IDX_94A935BE19EB6921 (client_id), INDEX IDX_94A935BE7B00651C (status), UNIQUE INDEX checkout_order_reference_unique (reference), UNIQUE INDEX checkout_order_session_unique (stripe_session_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE checkout_order ADD CONSTRAINT FK_94A935BE19EB6921 FOREIGN KEY (client_id) REFERENCES client (id)');

        $this->addSql("ALTER TABLE payment ADD provider VARCHAR(20) NOT NULL DEFAULT 'helloasso', ADD reference VARCHAR(20) DEFAULT NULL, ADD checkout_order_id INT DEFAULT NULL, CHANGE hello_asso_payment_id hello_asso_payment_id INT DEFAULT NULL, CHANGE hello_asso_config_id hello_asso_config_id INT DEFAULT NULL");
        $this->addSql('ALTER TABLE payment ALTER provider DROP DEFAULT');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840DA4BACF44 FOREIGN KEY (checkout_order_id) REFERENCES checkout_order (id)');
        $this->addSql('CREATE INDEX IDX_6D28840DA4BACF44 ON payment (checkout_order_id)');
        $this->addSql('CREATE UNIQUE INDEX client_payment_reference_unique ON payment (client_id, reference)');
    }

    public function down(Schema $schema): void
    {
        // Stripe payments have no HelloAsso id/form: they cannot survive the NOT NULL restore.
        $this->addSql("DELETE FROM payment WHERE provider <> 'helloasso'");
        $this->addSql('ALTER TABLE payment DROP FOREIGN KEY FK_6D28840DA4BACF44');
        $this->addSql('DROP INDEX client_payment_reference_unique ON payment');
        $this->addSql('DROP INDEX IDX_6D28840DA4BACF44 ON payment');
        $this->addSql('ALTER TABLE payment DROP provider, DROP reference, DROP checkout_order_id, CHANGE hello_asso_payment_id hello_asso_payment_id INT NOT NULL, CHANGE hello_asso_config_id hello_asso_config_id INT NOT NULL');
        $this->addSql('ALTER TABLE checkout_order DROP FOREIGN KEY FK_94A935BE19EB6921');
        $this->addSql('DROP TABLE checkout_order');
    }
}
