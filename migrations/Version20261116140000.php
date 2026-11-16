<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Extended payment page customization (cyllos-pay): type style, corners,
 * background, wording (title, intro, button, success message, support line)
 * and flow options (confirmation step, quick amounts), exposed through
 * pay_client_view. CREATE OR REPLACE VIEW keeps the SELECT grant of the
 * payment site's MariaDB user.
 */
final class Version20261116140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add payment page look, wording and flow options to stripe_config and pay_client_view.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE stripe_config
            ADD pay_font VARCHAR(10) DEFAULT NULL, ADD pay_radius VARCHAR(10) DEFAULT NULL, ADD pay_background VARCHAR(10) DEFAULT NULL,
            ADD pay_title VARCHAR(80) DEFAULT NULL, ADD pay_intro VARCHAR(300) DEFAULT NULL, ADD pay_button_label VARCHAR(40) DEFAULT NULL,
            ADD pay_success_message VARCHAR(300) DEFAULT NULL, ADD pay_support_text VARCHAR(200) DEFAULT NULL,
            ADD pay_show_confirm_step TINYINT DEFAULT 1 NOT NULL, ADD pay_quick_amounts VARCHAR(60) DEFAULT NULL");

        $this->addSql("CREATE OR REPLACE VIEW pay_client_view AS
            SELECT c.slug AS slug, c.name AS name, s.min_amount AS min_amount, s.max_amount AS max_amount,
                   s.brand_primary_color AS brand_primary_color, s.brand_background_color AS brand_background_color,
                   s.brand_surface_color AS brand_surface_color, s.brand_text_color AS brand_text_color,
                   c.logo_filename AS logo_filename,
                   s.pay_font AS pay_font, s.pay_radius AS pay_radius, s.pay_background AS pay_background,
                   s.pay_title AS pay_title, s.pay_intro AS pay_intro, s.pay_button_label AS pay_button_label,
                   s.pay_success_message AS pay_success_message, s.pay_support_text AS pay_support_text,
                   s.pay_show_confirm_step AS pay_show_confirm_step, s.pay_quick_amounts AS pay_quick_amounts
            FROM client c
            INNER JOIN stripe_config s ON s.client_id = c.id
            WHERE c.active = 1 AND c.payment_provider = 'stripe' AND s.active = 1");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("CREATE OR REPLACE VIEW pay_client_view AS
            SELECT c.slug AS slug, c.name AS name, s.min_amount AS min_amount, s.max_amount AS max_amount,
                   s.brand_primary_color AS brand_primary_color, s.brand_background_color AS brand_background_color,
                   s.brand_surface_color AS brand_surface_color, s.brand_text_color AS brand_text_color,
                   c.logo_filename AS logo_filename
            FROM client c
            INNER JOIN stripe_config s ON s.client_id = c.id
            WHERE c.active = 1 AND c.payment_provider = 'stripe' AND s.active = 1");
        $this->addSql('ALTER TABLE stripe_config DROP pay_font, DROP pay_radius, DROP pay_background, DROP pay_title, DROP pay_intro, DROP pay_button_label, DROP pay_success_message, DROP pay_support_text, DROP pay_show_confirm_step, DROP pay_quick_amounts');
    }
}
