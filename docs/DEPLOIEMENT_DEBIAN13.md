# Installation de Cyllos sur Debian 13 (Trixie) — procédure complète

Ce guide part du principe que la machine est **vierge** : seul le système
Debian 13 de base est installé, rien d'autre (pas de PHP, pas de MariaDB, pas
de serveur web). Chaque commande est à exécuter dans l'ordre. Si une étape
est déjà faite sur ta machine (par exemple si une base MariaDB existe déjà),
vérifie avec la commande de contrôle donnée en début de section et passe à
la suivante si c'est bon.

Toutes les commandes supposent un utilisateur avec les droits `sudo`. Adapte
`cyllos.exemple.fr` et les mots de passe par les tiens.

---

## 0. Vérifications de départ

```bash
cat /etc/os-release | grep VERSION
whoami
sudo -v
```

La première commande doit afficher `VERSION="13 (trixie)"` (ou équivalent).
La dernière te demande ton mot de passe `sudo` — si elle réussit sans
erreur, la suite peut commencer.

```bash
sudo apt update && sudo apt upgrade -y
```

Met le système à jour avant de commencer. Redémarre si le noyau a été mis à
jour (`sudo reboot`), puis reconnecte-toi en SSH.

---

## 1. Paquets système de base

```bash
sudo apt install -y curl wget unzip git ca-certificates apt-transport-https lsb-release gnupg2
```

Ces outils sont nécessaires pour la suite (téléchargement, décompression,
gestion de dépôts APT tiers, clonage du dépôt Git).

---

## 2. PHP et ses extensions

Cyllos nécessite PHP 8.4 ou supérieur — le `composer.lock` du projet pin des
versions de paquets qui exigent PHP ≥ 8.4, même si `composer.json` affiche
`>=8.2` (c'est le `.lock`, les versions réellement installées, qui fait
foi). **Debian 13 fournit PHP 8.3 par défaut, ce qui n'est pas suffisant** —
un dépôt tiers est nécessaire. Vérifie d'abord ce que propose Debian, au cas
où ça aurait changé depuis l'écriture de ce guide :

```bash
apt-cache policy php-cli 2>/dev/null | head -5
```

### Si la version proposée est déjà ≥ 8.4

Installe directement depuis les dépôts Debian :

```bash
sudo apt install -y php-fpm php-cli php-mysql php-mbstring php-xml \
    php-curl php-intl php-opcache php-bcmath php-zip
```

### Sinon (cas actuel de Debian 13, qui livre PHP 8.3)

Ajoute le dépôt tiers [Sury](https://packages.sury.org/php/), qui fournit
des versions PHP à jour pour Debian :

```bash
curl -fsSL https://packages.sury.org/php/apt.gpg | sudo tee /etc/apt/trusted.gpg.d/php.gpg >/dev/null
echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" | sudo tee /etc/apt/sources.list.d/php.list
sudo apt update
sudo apt install -y php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring \
    php8.4-xml php8.4-curl php8.4-intl php8.4-opcache php8.4-bcmath php8.4-zip
sudo update-alternatives --set php /usr/bin/php8.4
```

La dernière ligne est nécessaire si un PHP 8.3 était déjà présent : sans
elle, la commande `php` du CLI peut continuer à pointer vers l'ancienne
version alors que PHP-FPM utilise la nouvelle.

### Vérification

```bash
php -v
```

Doit afficher `PHP 8.4.x` ou plus récent. Note le numéro de version exact
(ex. `8.4` ou `8.5`) — il sera réutilisé dans les chemins de configuration
plus bas (remplace `8.4` par ta version si différente).

### Réglages PHP-FPM recommandés

Édite le fichier `php.ini` du pool par défaut (adapte le chemin à ta
version PHP) :

```bash
sudo nano /etc/php/8.4/fpm/php.ini
```

Vérifie/ajuste ces valeurs :

```ini
memory_limit = 256M
upload_max_filesize = 8M
post_max_size = 8M
max_execution_time = 60
```

`upload_max_filesize`/`post_max_size` couvrent l'upload de logo client
(limité à 2 Mo côté application, mais une marge évite les erreurs PHP
génériques peu explicites en cas de dépassement).

---

## 3. Composer

```bash
curl -sS https://getcomposer.org/installer -o composer-setup.php
php composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm composer-setup.php
composer --version
```

---

## 4. MariaDB

Vérifie d'abord si un serveur MySQL/MariaDB tourne déjà :

```bash
systemctl status mariadb 2>/dev/null || systemctl status mysql 2>/dev/null
```

### Si rien n'est installé

```bash
sudo apt install -y mariadb-server mariadb-client
sudo systemctl enable --now mariadb
```

Sécurise l'installation (mot de passe root, suppression des comptes de
démo) :

```bash
sudo mysql_secure_installation
```

Répondre `Y` (oui) à toutes les questions est le choix par défaut
raisonnable pour un serveur de production.

### Création de la base et de l'utilisateur applicatif

```bash
sudo mysql -u root -p -e "
CREATE DATABASE cyllos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'cyllos'@'localhost' IDENTIFIED BY 'CHANGE_MOI_mot_de_passe_solide';
GRANT ALL PRIVILEGES ON cyllos.* TO 'cyllos'@'localhost';
FLUSH PRIVILEGES;
"
```

**Remplace `CHANGE_MOI_mot_de_passe_solide` par un vrai mot de passe** — tu
le réutiliseras à l'étape 8.

---

## 5. Serveur web (Nginx)

```bash
sudo apt install -y nginx
sudo systemctl enable --now nginx
curl -s http://localhost | head -5
```

La dernière commande doit renvoyer du HTML (la page par défaut de Nginx) —
confirme que le serveur web répond.

---

## 6. Utilisateur système dédié à l'application

Ne jamais faire tourner l'application sous `root`. Crée un utilisateur
système dédié, membre du groupe du serveur web :

```bash
sudo adduser --system --group --home /var/www/cyllos --shell /bin/bash cyllos
sudo usermod -aG cyllos www-data
```

---

## 7. Récupération du code

```bash
sudo -u cyllos git clone https://github.com/CylaosICT/Cyllos.git /var/www/cyllos
cd /var/www/cyllos
```

Si le dépôt est privé, configure d'abord un accès (clé SSH déployée pour
l'utilisateur `cyllos`, ou jeton d'accès personnel en HTTPS) avant cette
commande.

---

## 8. Installation des dépendances et configuration

```bash
sudo -u cyllos composer install --no-dev --optimize-autoloader --no-interaction
sudo -u cyllos cp .env .env.local
```

Édite `.env.local` :

```bash
sudo -u cyllos nano .env.local
```

Contenu minimal à y placer (remplace les valeurs) :

```
APP_ENV=prod
APP_SECRET=
DATABASE_URL="mysql://cyllos:CHANGE_MOI_mot_de_passe_solide@127.0.0.1:3306/cyllos?serverVersion=10.11.2-MariaDB&charset=utf8mb4"
MAILER_DSN=smtp://utilisateur:motdepasse@smtp.exemple.fr:587
MAILER_FROM=cyllos@cylaos.fr
GITHUB_UPSTREAM_REPO=CylaosICT/Cyllos
GITHUB_UPSTREAM_BRANCH=main
APP_ENCRYPTION_KEY=
```

Génère `APP_SECRET` (chaîne aléatoire quelconque) :

```bash
php -r "echo bin2hex(random_bytes(16)) . PHP_EOL;"
```

Copie le résultat dans `APP_SECRET=` du fichier `.env.local`.

Génère la clé de chiffrement des secrets HelloAsso/Cyclos :

```bash
sudo -u cyllos php bin/console app:generate-encryption-key
```

Copie la valeur affichée (`APP_ENCRYPTION_KEY=...`) dans `.env.local`.
**Cette clé ne doit jamais être committée ni partagée par un canal non
chiffré** — sans elle, tous les secrets clients stockés en base deviennent
illisibles.

---

## 9. Base de données de l'application

```bash
sudo -u cyllos php bin/console doctrine:migrations:migrate --no-interaction --env=prod
```

Compile le cache et les assets front (CSS/JS servis par Symfony
AssetMapper, pas de build externe nécessaire) :

```bash
sudo -u cyllos php bin/console cache:clear --env=prod
sudo -u cyllos php bin/console asset-map:compile --env=prod
```

Crée le premier compte administrateur (voit tous les clients) :

```bash
sudo -u cyllos php bin/console app:user:create admin@cylaos.fr "CHANGE_MOI_mot_de_passe_admin" --admin
```

---

## 10. Pool PHP-FPM dédié

```bash
sudo nano /etc/php/8.4/fpm/pool.d/cyllos.conf
```

Contenu :

```ini
[cyllos]
user = cyllos
group = cyllos
listen = /run/php/php8.4-fpm-cyllos.sock
listen.owner = www-data
listen.group = www-data
pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 4
```

```bash
sudo systemctl restart php8.4-fpm
sudo systemctl status php8.4-fpm
```

Le `status` doit afficher `active (running)` sans erreur.

---

## 11. Configuration Nginx

```bash
sudo nano /etc/nginx/sites-available/cyllos
```

Contenu :

```nginx
server {
    listen 80;
    server_name cyllos.exemple.fr;
    root /var/www/cyllos/public;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/run/php/php8.4-fpm-cyllos.sock;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT $document_root;
        internal;
    }

    location ~ \.php$ {
        return 404;
    }
}
```

Active le site et recharge Nginx :

```bash
sudo ln -s /etc/nginx/sites-available/cyllos /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl reload nginx
```

`nginx -t` doit afficher `syntax is ok` / `test is successful` avant de
recharger.

### Test à ce stade

```bash
curl -sI http://cyllos.exemple.fr/login
```

Doit renvoyer `HTTP/1.1 200 OK`. Si tu n'as pas encore de nom de domaine
pointé vers ce serveur, teste en local sur la machine avec :

```bash
curl -sI -H "Host: cyllos.exemple.fr" http://127.0.0.1/login
```

---

## 12. HTTPS (recommandé, nécessite un vrai nom de domaine)

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d cyllos.exemple.fr
```

Certbot modifie automatiquement la configuration Nginx pour rediriger le
HTTP vers HTTPS et renouvelle le certificat automatiquement (timer
systemd installé avec le paquet).

---

## 13. Worker du Scheduler (rattrapage HelloAsso + purge)

Sans ce processus actif en continu, aucune tâche planifiée ne se
déclenche — ni le rattrapage HelloAsso (webhooks manqués), ni la purge des
vieux paiements.

```bash
sudo nano /etc/systemd/system/cyllos-scheduler.service
```

Contenu :

```ini
[Unit]
Description=Cyllos - worker Scheduler (rattrapage HelloAsso + purge)
After=network.target mariadb.service

[Service]
Type=simple
User=cyllos
WorkingDirectory=/var/www/cyllos
ExecStart=/usr/bin/php bin/console messenger:consume scheduler_default --env=prod --time-limit=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now cyllos-scheduler
sudo systemctl status cyllos-scheduler
```

Le `status` doit afficher `active (running)`.

---

## 14. Vérification finale

Checklist à passer avant de considérer l'installation terminée :

```bash
# PHP-FPM actif
sudo systemctl is-active php8.4-fpm

# MariaDB actif
sudo systemctl is-active mariadb

# Nginx actif
sudo systemctl is-active nginx

# Worker Scheduler actif
sudo systemctl is-active cyllos-scheduler

# Page de connexion accessible
curl -sI -H "Host: cyllos.exemple.fr" http://127.0.0.1/login | head -1

# Migrations bien appliquées (la ligne "New" doit valoir 0)
sudo -u cyllos php bin/console doctrine:migrations:status --env=prod | grep "New"
```

Chaque `is-active` doit répondre `active`. Une fois tout vert, connecte-toi
sur `https://cyllos.exemple.fr/login` avec le compte admin créé à l'étape 9
et crée ton premier client depuis `/admin/clients`.

---

## 15. Mises à jour ultérieures

Une fois l'application installée, deux options pour la mettre à jour :

- **Depuis l'interface** : `/dev/version` (compte `ROLE_DEVELOPER` ou
  `ROLE_CEO`) propose un bouton "Déployer la mise à jour" quand une nouvelle
  version est disponible sur le dépôt canonique.
- **Manuellement** :

```bash
cd /var/www/cyllos
sudo -u cyllos git pull --ff-only
sudo -u cyllos composer install --no-dev --optimize-autoloader --no-interaction
sudo -u cyllos php bin/console doctrine:migrations:migrate --no-interaction --env=prod
sudo -u cyllos php bin/console cache:clear --env=prod
sudo systemctl restart php8.4-fpm cyllos-scheduler
```

---

## Dépannage rapide

| Symptôme | Cause probable | Vérifier |
|---|---|---|
| 502 Bad Gateway | PHP-FPM arrêté ou mauvais chemin de socket | `sudo systemctl status php8.4-fpm`, chemin `listen` dans le pool vs `fastcgi_pass` dans Nginx |
| Page blanche / 500 sans détail | `APP_ENV=prod` masque les erreurs par design | `tail -f var/log/prod.log` |
| "APP_ENCRYPTION_KEY must be..." | Clé absente ou mal copiée dans `.env.local` | Regénérer avec `app:generate-encryption-key` |
| Rattrapage HelloAsso ne se déclenche jamais | Worker Scheduler arrêté | `sudo systemctl status cyllos-scheduler` |
| Permission denied sur les fichiers uploadés | Mauvais propriétaire sur `var/`/`public/uploads` | `sudo chown -R cyllos:cyllos /var/www/cyllos/var /var/www/cyllos/public/uploads` |
