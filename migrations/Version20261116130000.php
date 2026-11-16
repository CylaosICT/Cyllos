<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Payment page identity: optional brand colours on stripe_config, exposed (with
 * the client's logo filename) through pay_client_view. CREATE OR REPLACE VIEW
 * keeps the SELECT grant of the payment site's MariaDB user.
 */
final class Version20261116130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add brand colours to stripe_config and expose them (and the logo) in pay_client_view.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stripe_config ADD brand_primary_color VARCHAR(7) DEFAULT NULL, ADD brand_background_color VARCHAR(7) DEFAULT NULL, ADD brand_surface_color VARCHAR(7) DEFAULT NULL, ADD brand_text_color VARCHAR(7) DEFAULT NULL');
        $this->addSql("CREATE OR REPLACE VIEW pay_client_view AS
            SELECT c.slug AS slug, c.name AS name, s.min_amount AS min_amount, s.max_amount AS max_amount,
                   s.brand_primary_color AS brand_primary_color, s.brand_background_color AS brand_background_color,
                   s.brand_surface_color AS brand_surface_color, s.brand_text_color AS brand_text_color,
                   c.logo_filename AS logo_filename
            FROM client c
            INNER JOIN stripe_config s ON s.client_id = c.id
            WHERE c.active = 1 AND c.payment_provider = 'stripe' AND s.active = 1");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("CREATE OR REPLACE VIEW pay_client_view AS
            SELECT c.slug AS slug, c.name AS name, s.min_amount AS min_amount, s.max_amount AS max_amount
            FROM client c
            INNER JOIN stripe_config s ON s.client_id = c.id
            WHERE c.active = 1 AND c.payment_provider = 'stripe' AND s.active = 1");
        $this->addSql('ALTER TABLE stripe_config DROP brand_primary_color, DROP brand_background_color, DROP brand_surface_color, DROP brand_text_color');
    }
}
