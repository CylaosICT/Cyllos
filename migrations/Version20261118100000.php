<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Amount rules of the payment page: range, list of amounts or single amount,
 * and an optional Cyclos credit different from the Stripe payment. The rules
 * are exposed through pay_client_view, the credited amount of an order through
 * pay_order_view. CREATE OR REPLACE VIEW keeps the SELECT grants of the payment
 * site's MariaDB user.
 */
final class Version20261118100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add amount modes and Cyclos credit amount (stripe_config, checkout_order) and expose them in the pay views.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE stripe_config
            ADD pay_amount_mode VARCHAR(10) DEFAULT 'range' NOT NULL, ADD pay_amount_choices VARCHAR(255) DEFAULT NULL,
            ADD pay_fixed_amount INT DEFAULT NULL, ADD pay_fixed_credit INT DEFAULT NULL,
            ADD pay_credit_rate DOUBLE PRECISION DEFAULT NULL, ADD pay_credit_unit VARCHAR(41) DEFAULT NULL");
        $this->addSql('ALTER TABLE checkout_order ADD credit_amount_cents INT DEFAULT NULL');
        $this->addSql('UPDATE checkout_order SET credit_amount_cents = amount_cents');

        $this->addSql($this->clientView(true));
        $this->addSql($this->orderView(true));
    }

    public function down(Schema $schema): void
    {
        $this->addSql($this->clientView(false));
        $this->addSql($this->orderView(false));
        $this->addSql('ALTER TABLE checkout_order DROP credit_amount_cents');
        $this->addSql('ALTER TABLE stripe_config DROP pay_amount_mode, DROP pay_amount_choices, DROP pay_fixed_amount, DROP pay_fixed_credit, DROP pay_credit_rate, DROP pay_credit_unit');
    }

    private function clientView(bool $withAmountRules): string
    {
        $extra = $withAmountRules
            ? ', s.pay_amount_mode AS pay_amount_mode, s.pay_amount_choices AS pay_amount_choices, s.pay_fixed_amount AS pay_fixed_amount,
                   s.pay_fixed_credit AS pay_fixed_credit, s.pay_credit_rate AS pay_credit_rate, s.pay_credit_unit AS pay_credit_unit'
            : '';

        return "CREATE OR REPLACE VIEW pay_client_view AS
            SELECT c.slug AS slug, c.name AS name, s.min_amount AS min_amount, s.max_amount AS max_amount,
                   s.brand_primary_color AS brand_primary_color, s.brand_background_color AS brand_background_color,
                   s.brand_surface_color AS brand_surface_color, s.brand_text_color AS brand_text_color,
                   c.logo_filename AS logo_filename,
                   s.pay_font AS pay_font, s.pay_radius AS pay_radius, s.pay_background AS pay_background,
                   s.pay_title AS pay_title, s.pay_intro AS pay_intro, s.pay_button_label AS pay_button_label,
                   s.pay_success_message AS pay_success_message, s.pay_support_text AS pay_support_text,
                   s.pay_show_confirm_step AS pay_show_confirm_step, s.pay_quick_amounts AS pay_quick_amounts{$extra}
            FROM client c
            INNER JOIN stripe_config s ON s.client_id = c.id
            WHERE c.active = 1 AND c.payment_provider = 'stripe' AND s.active = 1";
    }

    private function orderView(bool $withCredit): string
    {
        $credit = $withCredit ? ', COALESCE(o.credit_amount_cents, o.amount_cents) AS credit_amount_cents' : '';

        return "CREATE OR REPLACE VIEW pay_order_view AS
            SELECT o.reference AS reference, c.slug AS client_slug, o.amount_cents AS amount_cents{$credit},
                   o.status AS status, o.created_at AS created_at, o.paid_at AS paid_at,
                   CASE
                       WHEN p.id IS NULL THEN NULL
                       WHEN p.status IN ('success', 'success_auto', 'manual_credit') THEN 'credited'
                       WHEN p.status IN ('fail', 'too_high', 'too_late') THEN 'problem'
                       ELSE 'processing'
                   END AS credit_status
            FROM checkout_order o
            INNER JOIN client c ON c.id = o.client_id
            LEFT JOIN payment p ON p.checkout_order_id = o.id";
    }
}
