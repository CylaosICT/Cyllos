<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Read-only views for the separate payment site (cyllos-pay). That site's
 * MariaDB user gets SELECT on these two views only (see docs/PAY_SITE_DB.sql):
 * no table, no secret, no customer e-mail is reachable through it.
 */
final class Version20261116120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create pay_client_view and pay_order_view (read-only surface for the payment site).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE VIEW pay_client_view AS
            SELECT c.slug AS slug, c.name AS name, s.min_amount AS min_amount, s.max_amount AS max_amount
            FROM client c
            INNER JOIN stripe_config s ON s.client_id = c.id
            WHERE c.active = 1 AND c.payment_provider = 'stripe' AND s.active = 1");

        // credit_status follows the Cyclos credit once Stripe has been paid:
        // NULL (no payment recorded yet), processing, credited, problem.
        $this->addSql("CREATE VIEW pay_order_view AS
            SELECT o.reference AS reference, c.slug AS client_slug, o.amount_cents AS amount_cents,
                   o.status AS status, o.created_at AS created_at, o.paid_at AS paid_at,
                   CASE
                       WHEN p.id IS NULL THEN NULL
                       WHEN p.status IN ('success', 'success_auto', 'manual_credit') THEN 'credited'
                       WHEN p.status IN ('fail', 'too_high', 'too_late') THEN 'problem'
                       ELSE 'processing'
                   END AS credit_status
            FROM checkout_order o
            INNER JOIN client c ON c.id = o.client_id
            LEFT JOIN payment p ON p.checkout_order_id = o.id");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP VIEW pay_order_view');
        $this->addSql('DROP VIEW pay_client_view');
    }
}
