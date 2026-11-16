-- À exécuter UNE FOIS par un administrateur MariaDB (après les migrations de Cyllos).
-- Crée l'utilisateur du site de paiement (cyllos-pay) : lecture seule sur deux vues.
-- Remplacez le mot de passe, et l'hôte si le site B est sur une autre machine.
CREATE USER 'cyllos_pay'@'localhost' IDENTIFIED BY 'CHANGER_CE_MOT_DE_PASSE';
GRANT SELECT ON cyllos.pay_client_view TO 'cyllos_pay'@'localhost';
GRANT SELECT ON cyllos.pay_order_view  TO 'cyllos_pay'@'localhost';
FLUSH PRIVILEGES;
