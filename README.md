# **Proprietary Software — Production use requires written authorization from Cylaos ICT.**

# Cyllos

Cyllos écoute les paiements HelloAsso de plusieurs clients (monnaies locales) et
crédite automatiquement leur compte Cyclos correspondant. C'est une réécriture
multi-tenant d'[Hellos](https://github.com/jymaire/hellos), sous Symfony : une seule
application gère l'ensemble des clients, chacun avec un ou plusieurs formulaires
HelloAsso (typiquement un pour les particuliers, un pour les professionnels) et sa
propre connexion Cyclos.

## Fonctionnement

### Modèle multi-tenant

Une seule instance de Cyllos gère tous les clients Cylaos. Chaque `Client` porte
un slug unique et possède sa propre configuration. Le prestataire de paiement
(`paymentProvider` : HelloAsso ou Stripe) est choisi à la création du client,
à l'étape 2 de l'assistant, et détermine la configuration demandée ensuite :
- `StripeConfig` (clients Stripe, une par client) : clé API Stripe (restreinte
  `rk_…` recommandée, ou `sk_…`) et secret de signature du webhook (`whsec_…`),
  tous deux chiffrés en base et jamais réaffichés (seul un indice « mode +
  4 derniers caractères » est visible), plus les montants minimum/maximum par
  paiement. Les paiements arrivent directement sur le compte Stripe du client.
  Flux : le site de paiement séparé (`cyllos-pay`, à venir) appelle
  `POST /internal/account-check` (étape « confirmer le compte », sans paiement) puis
  `POST /internal/checkout` (signé HMAC-SHA256 de `{timestamp}.{corps}` avec
  `PAY_INTERNAL_SECRET`, en-têtes `X-Cyllos-Timestamp` / `X-Cyllos-Signature`,
  ±300 s) avec `{clientSlug, email, amountCents, clientIp}` ; Cyllos vérifie
  le compte Cyclos, crée une `CheckoutOrder` puis une session Stripe Checkout
  (pleine page) et renvoie l'URL de redirection. Stripe notifie ensuite
  `POST /webhook/stripe/{slug}` (signature `Stripe-Signature` vérifiée) ; le
  paiement est enregistré (montant et devise contrôlés, idempotent) puis crédité
  sur Cyclos comme pour HelloAsso. Remboursements et litiges sont journalisés
  pour suivi manuel. Variables d'environnement : `PAY_SITE_BASE_URL` (URL du
  site de paiement, pour les pages de retour) et `PAY_INTERNAL_SECRET`
  (vide = endpoint interne désactivé, 503).
- `HelloAssoConfig` (clients HelloAsso, un ou plusieurs par client) : identifiants API HelloAsso
  (client ID/secret chiffré), organisation, formulaire ciblé, montant maximum
  autorisé par paiement, libellé et statut actif/inactif. Un client garde
  toujours au moins un formulaire actif ; un formulaire désactivé ne peut être
  supprimé que s'il n'a aucun paiement enregistré et n'est pas le formulaire
  principal du client (le plus ancien — jamais supprimable, même sans
  historique) ;
- `CyclosConfig` : URL de l'instance Cyclos, utilisateur technique et mot de
  passe (chiffré), groupes Cyclos "pro"/"particulier" et types d'émission
  associés ;
- `ClientSetting` : paiements Cyclos activés ou non (mode "aperçu" sinon),
  crédit automatique activé ou non, e-mail de contact du client (`mailRecipient`,
  adresse unique : alertes techniques, pré-remplissage du premier compte
  utilisateur) et deux notifications indépendantes envoyées à cette même
  adresse (distincte des comptes utilisateurs) : à chaque
  paiement réussi (`notifySuccessOnPayment`) et à chaque échec
  (`notifyFailureOnPayment`). Réglables depuis la fiche client côté admin,
  mais aussi en libre-service par le client lui-même depuis `/settings`
  (carte "Notifications de paiement", visible uniquement des comptes
  `ROLE_CLIENT`) ;
- `EmailAlias` (zéro ou plusieurs) : règles persistantes de correction
  d'email — quand un payeur utilise sur HelloAsso une adresse différente de
  celle de son compte Cyclos, une règle `sourceEmail → targetEmail` fait que
  tous ses paiements futurs sont automatiquement crédités sur le bon compte,
  sans qu'il faille corriger le problème à chaque fois. Gérées depuis la
  fiche client (bloc "Correspondances d'e-mail").

Les secrets (`clientSecret` HelloAsso, clés Stripe, mot de passe Cyclos) sont chiffrés en
base avec `APP_ENCRYPTION_KEY` via `SecretEncryptor` — jamais stockés en clair.

### Cycle de vie d'un paiement

1. **Réception** : HelloAsso notifie Cyllos par webhook — une seule URL par
   client (`POST /webhook/helloasso/{slug}/{token}`), quel que soit son nombre de
   formulaires ; c'est le `formSlug` inclus dans la notification qui indique
   lequel des formulaires actifs du client est concerné. HelloAsso ne signant
   pas ses notifications, le `token` (secret propre au client, 64 caractères
   hexadécimaux, visible et régénérable sur la fiche client) est ce qui
   authentifie l'appel : un `token` absent ou incorrect renvoie `404` sans rien
   créer. `PaymentProcessor`
   valide la notification (formulaire actif reconnu, montant sous la limite
   *de ce formulaire*, état `Authorized`/`Waiting`, pas de doublon) et crée un
   `Payment`.
2. **Décision** : le webhook enregistre le `Payment` en `Todo` et répond
   aussitôt à HelloAsso ; la suite se fait dans un worker (message
   `ProcessPaymentMessage` sur la file `async`), pour ne pas dépasser le délai
   d'attente du webhook. Dans le worker :
   - si le crédit automatique est désactivé pour ce client → aucun message n'est
     même émis, le paiement reste `Todo`, à créditer manuellement depuis
     `/admin` ou l'espace client ;
   - si le paiement est trop en retard (> 12h, `NUMBER_LATE_HOURS_ACCEPTED`) ou
     encore `Waiting` côté HelloAsso → il est marqué en conséquence et un mail
     d'alerte est envoyé, sans crédit automatique ;
   - sinon, `PaymentProcessor` tente de créditer le compte Cyclos correspondant.
   Le traitement est idempotent : un message rejoué retrouve le paiement dans un
   statut autre que `Todo` et ne fait rien (ni double crédit, ni double mail).
3. **Crédit Cyclos** (`CyclosClient`) : si une règle `EmailAlias` existe pour
   ce client et cet email payeur (voir plus bas), l'email de remplacement est
   utilisé directement ; sinon recherche de l'utilisateur par l'email payeur
   (avec repli sur un email alternatif récupéré via l'API HelloAsso si
   introuvable), détermination du type d'émission selon son groupe Cyclos,
   vérification anti-doublon (recherche la description attendue parmi les 50
   dernières transactions de crédit de l'utilisateur — pas seulement la
   dernière, voir `CyclosClient::DUPLICATE_CHECK_WINDOW`), puis exécution du
   paiement — ou simple `preview` si les paiements Cyclos sont désactivés pour
   ce client (`PreviewOk`).
4. **Rattrapage** : en complément du webhook temps réel, `app:helloasso:fetch`
   interroge l'historique HelloAsso de chaque client actif pour récupérer tout
   paiement manqué (notification perdue, HelloAsso indisponible, etc.). Deux
   usages de ce même mécanisme, avec un comportement différent :
   - **planifié** (Scheduler) : `app:helloasso:fetch` ne fait pas le travail
     lui-même, il pousse un message `FetchClientPaymentsMessage` par client actif
     sur la file `async` (avec un délai aléatoire de 0–30 s pour étaler la charge),
     et le worker traite chaque client indépendamment — un client lent ou en
     erreur ne bloque plus les autres. Sans déclencher de crédit automatique :
     les paiements récupérés restent `Todo`, c'est un filet de sécurité, pas une
     deuxième voie de crédit. (`--sync` force le traitement en ligne, `--client
     <slug>` ne traite qu'un client.)
   - **synchro manuelle** (bouton "Synchro HelloAsso" côté admin) : émet un
     `FetchClientPaymentsMessage` (avec `attemptAutomaticCredit: true`) traité
     par le worker, hors de la requête HTTP. Chaque paiement récupéré passe
     alors dans la même décision de crédit automatique que le webhook (mêmes
     règles : `paymentAutomaticEnabled`, `maxAmount`, délai de 12h). Les
     résultats apparaissent dans la liste des paiements dès que le worker a
     terminé.

Les appels sortants HelloAsso et Cyclos passent par des clients HTTP qui
réessaient automatiquement les échecs transitoires (429/5xx, erreurs réseau) avec
un backoff exponentiel — sauf le POST de paiement Cyclos, jamais rejoué pour ne
pas risquer un double crédit (`App\Http\NonMutatingRetryStrategy`). La route du
webhook est par ailleurs limitée en débit par client (`rate_limiter.webhook`,
30 requêtes/minute par slug).

Chaque paiement (`Payment`) garde un statut (`todo`, `too_high`, `too_late`,
`preview_ok`, `success`, `success_auto`, `fail`, `waiting`) et un message
d'erreur le cas échéant, visible dans les listes de paiements.

### Espaces applicatifs

- **`/admin`** (`ROLE_ADMIN`) : vue transverse sur tous les clients. La page
  d'accueil est un **tableau de bord** (`/admin/tableau-de-bord`) : paiements des
  30 derniers jours par statut (compteurs cliquables + barre de répartition),
  taux d'échec, état de la file `async` (en attente / en échec / âge du plus
  ancien), paiements bloqués en `todo` chez un client en crédit automatique,
  clients actifs sans paiement reçu depuis 48 h (webhook probablement mal
  configuré) et les derniers échecs. Gestion des clients (assistant de création
  en 4 étapes, config Cyclos/réglages,
  formulaire(s) HelloAsso ajoutables/désactivables/supprimables), tous les
  paiements avec filtre par client (colonne "Formulaire" indiquant lequel a
  reçu le paiement, colonne "E-mail HelloAsso" avec indicateur si une règle
  `EmailAlias` existe déjà et raccourci pour en créer une pré-remplie sinon), crédit/suppression
  manuels, synchro HelloAsso à la demande, recherche globale, et comptes
  utilisateurs par client (création, réinitialisation de mot de passe,
  activation/désactivation, suppression — un compte désactivé ne peut plus
  se connecter mais reste visible, contrairement à la suppression ; un
  compte doit d'ailleurs être désactivé avant de pouvoir être supprimé,
  même règle que pour un `Client`).
- **`/app`** (`ROLE_CLIENT`) : espace self-service pour un client — liste de
  ses seuls paiements (isolation garantie par `ClientOwnsPaymentVoter`),
  crédit et suppression, et gestion de ses propres règles `EmailAlias`
  (`/app/regles-email`) pour corriger lui-même un paiement dont l'e-mail
  HelloAsso ne correspond pas à son compte Cyclos, sans avoir à contacter
  Cylaos — toujours limité à son propre client, jamais un paramètre d'URL.
- **`/dev`** (`ROLE_DEVELOPER`) : journal d'activité (`ActivityLog`), qui trace
  les créations/modifications/suppressions d'entités sensibles, les
  évènements de connexion et les appels API sortants (HelloAsso/Cyclos), via
  des listeners Doctrine et Security ; page de version et mise à jour. Les
  appels API HelloAsso (bien plus nombreux, rattrapage chaque minute) sont
  masqués par défaut dans le journal — un bouton en haut de la page les
  réaffiche à la demande, sans affecter les appels Cyclos ni les lignes
  d'audit.
- **`/settings`** : self-service pour tout utilisateur connecté (thème clair/
  sombre, email, mot de passe, double authentification optionnelle).

### Rôles

- `ROLE_CLIENT` : accès à ses propres paiements uniquement. Seul rôle pouvant
  utiliser la réinitialisation de mot de passe en libre-service (voir
  ci-dessous).
- `ROLE_ADMIN` : accès global à `/admin` ; peut créer/gérer les clients et les
  comptes admin classiques, mais ne peut ni modifier ni supprimer un compte
  développeur ou CEO (visible en lecture seule dans `/admin/equipe`).
- `ROLE_DEVELOPER` (hérite de `ROLE_ADMIN`) : accès en plus au journal
  d'activité, y compris le droit de le vider entièrement, et peut déclencher
  une mise à jour de l'application depuis `/dev/version`.
- `ROLE_CEO` (hérite de `ROLE_ADMIN` et `ROLE_DEVELOPER`) : seul rôle habilité
  à attribuer `ROLE_DEVELOPER` à la création d'un compte, à gérer les comptes
  développeur/CEO, et à activer/désactiver des comptes admin.

Un compte désactivé (`active = false`) ne peut plus se connecter (appliqué via
`AppUserChecker`, un `UserCheckerInterface` exécuté à l'authentification).

### Mot de passe oublié

Réservé aux comptes clients (`ROLE_CLIENT`) — jamais aux comptes admin,
développeur ou CEO, qui doivent être réinitialisés par un développeur/CEO
(`/admin/equipe`). Ce filtre est appliqué côté serveur, pas seulement caché
dans l'interface. Le lien `/mot-de-passe-oublie` envoie un jeton à usage
unique (hash SHA-256 stocké en base, jamais le jeton en clair), valable 60
minutes ; le message de retour est identique que l'adresse existe ou non,
pour ne pas permettre l'énumération des comptes.

### Double authentification (2FA)

Optionnelle, activable par n'importe quel compte (admin, client, développeur,
CEO) depuis `/settings`. TOTP (RFC 6238) : secret par compte, code à 6
chiffres renouvelé toutes les 30 secondes, compatible avec toute application
d'authentification standard (Google Authenticator, Authy, 1Password...). Une
fois activée, chaque connexion demande le code en plus du mot de passe ; la
désactivation exige de resaisir le mot de passe du compte.

### Vérification de version

`/dev/version` (`ROLE_DEVELOPER`) compare le commit git actuellement déployé
au dernier commit de la branche du dépôt canonique de l'entreprise
(`GITHUB_UPSTREAM_REPO`/`GITHUB_UPSTREAM_BRANCH`, voir `.env`), via l'API
GitHub — lecture seule, rien n'est ni téléchargé ni exécuté automatiquement.
Si ce dépôt est privé, définir `GITHUB_TOKEN` (jeton en lecture seule) dans
`.env.local`. Si l'instance est déployée sans dossier `.git` (artefact de
build), définir `APP_COMMIT_SHA` au moment du déploiement pour que la
comparaison reste possible.

Quand l'application n'est pas à jour, un bouton "Déployer la mise à jour"
apparaît sur cette page pour `ROLE_DEVELOPER`/`ROLE_CEO`. Il exécute dans l'ordre
`git pull --ff-only`, `composer install`, les migrations Doctrine en attente,
`cache:clear`, `asset-map:compile`, puis (facultatif) `sudo -n systemctl restart
cyllos-worker cyllos-scheduler`, et affiche le résultat détaillé de chaque étape.
Un déploiement déjà en cours (verrou `var/deploy.lock`) fait échouer le second
clic avec un message explicite plutôt que d'empiler les commandes. Le
redémarrage des workers n'a lieu que si une règle sudo sans mot de passe existe
(voir `docs/DEPLOIEMENT_DEBIAN13.md`) ; sinon l'étape indique la commande à
lancer à la main, sans faire échouer le déploiement. C'est une action réelle sur
le checkout de production — voir [Déploiement en production](#déploiement-en-production)
pour les prérequis.

### Journal d'activité

`/dev/journal` peut être entièrement vidé (`ROLE_DEVELOPER`/`ROLE_CEO`
uniquement, pas un simple `ROLE_ADMIN`), après avoir resaisi le mot de passe
du compte connecté. Suppression définitive, sans corbeille ni export
préalable.

En complément, `app:activity-log:purge` (planifié une fois par jour) borne la
table : les traces d'appels API (`action` en `api.*`, le gros du volume)
expirent après `ACTIVITY_LOG_API_RETENTION_DAYS` (14 j par défaut), les lignes
d'audit après `ACTIVITY_LOG_RETENTION_DAYS` (365 j).

### Supervision (`/health`)

`GET /health` (non authentifié, pour une sonde externe) renvoie un JSON sur
l'état interne de Cyllos : base de données, file de messages `async` (nombre en
attente, en échec, âge du plus ancien) et paiements bloqués en `todo` chez un
client en crédit automatique (le symptôme d'un `cyllos-worker` arrêté). Code
HTTP `200` si `ok`/`degraded` (détail dans le corps), `503` si la base est
injoignable. Aucun appel à HelloAsso ni Cyclos n'est fait (ce serait les
solliciter à chaque interrogation).

### Rotation de la clé de chiffrement

Les secrets stockés en base — identifiants HelloAsso, mot de passe Cyclos, et
secret TOTP de chaque compte 2FA — sont chiffrés avec `SecretEncryptor`
(AES-256-GCM). Les nouvelles valeurs utilisent toujours `APP_ENCRYPTION_KEY` ; le
déchiffrement essaie cette clé puis, le cas échéant, chacune des clés listées
dans `APP_ENCRYPTION_KEYS_LEGACY` (séparées par des virgules). Pour changer de
clé sans interruption :

1. Générer une nouvelle clé : `php bin/console app:generate-encryption-key`.
2. Dans `.env.local` : déplacer la valeur actuelle de `APP_ENCRYPTION_KEY` vers
   `APP_ENCRYPTION_KEYS_LEGACY`, mettre la nouvelle clé dans `APP_ENCRYPTION_KEY`.
3. Recharger l'application (`cache:clear`, redémarrage PHP-FPM/workers).
4. Ré-chiffrer les secrets existants : `php bin/console app:secrets:reencrypt`
   (option `--dry-run` pour un aperçu). La commande couvre les identifiants
   HelloAsso, les mots de passe Cyclos et les secrets TOTP ; elle signale sans
   écraser tout secret qu'aucune clé configurée ne déchiffre.
5. Une fois `app:secrets:reencrypt` sans reste, vider `APP_ENCRYPTION_KEYS_LEGACY`.

## Prérequis

- PHP 8.4+ avec les extensions `pdo_mysql` et `openssl`
- Composer
- MySQL / MariaDB

## Installation

```bash
composer install
```

Configurer la base de données dans `.env.local` (voir `DATABASE_URL` dans `.env`
pour le format), puis générer une clé de chiffrement pour les secrets HelloAsso /
Cyclos stockés en base :

```bash
php bin/console app:generate-encryption-key
```

Copier la clé générée dans `.env.local` :

```
APP_ENCRYPTION_KEY=...
```

Créer la base et lancer les migrations :

```bash
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
```

Créer un premier compte administrateur Cylaos (voit tous les clients) :

```bash
php bin/console app:user:create admin@example.com "un-mot-de-passe-solide" --admin
```

## Lancer l'application en local

```bash
php -S 127.0.0.1:8000 -t public
```

## Configurer un client

Depuis `/admin/clients`, l'assistant de création (5 étapes : informations →
prestataire → configuration du prestataire → Cyclos → réglages) demande :
- le prestataire de paiement, via deux grandes tuiles Stripe / HelloAsso ;
- soit son premier formulaire HelloAsso (client ID/secret, organisation, formulaire),
  soit ses clés Stripe (clé API, secret de webhook, bornes de montant) ;
- sa connexion Cyclos (URL, utilisateur technique, groupes/émissions) ;
- ses réglages (paiements Cyclos actifs, mode automatique, email de notification).

L'URL du webhook à renseigner côté HelloAsso ("Intégrations et API") est
affichée en entier sur la fiche du client, dans la section pleine largeur
"URL de webhook HelloAsso" (sous les cartes de configuration, au-dessus des
comptes utilisateurs), avec un bouton **Copier** :
`/webhook/helloasso/{slug}/{token}`, où `token` est le secret propre au client.
Le bouton "Régénérer le jeton" (dans l'en-tête de cette section) en produit un
nouveau — l'URL doit alors être mise à jour dans HelloAsso, l'ancienne cessant
aussitôt d'être acceptée.

Si ce client utilise plusieurs formulaires HelloAsso (ex. un pour les
particuliers, un pour les professionnels), un second (ou troisième) formulaire
peut être ajouté depuis sa fiche (bouton "+ Ajouter un formulaire HelloAsso") —
les identifiants (URL API, Client ID, organisation, secret) sont pré-remplis à
partir du premier formulaire, à ajuster seulement si ce nouveau formulaire
utilise réellement un autre compte HelloAsso. La même URL de webhook s'utilise
pour tous les formulaires du client, rien à reconfigurer côté HelloAsso au-delà
d'y renseigner cette URL sur le nouveau formulaire.

Créer ensuite un compte pour ce client (accès limité à ses propres paiements) :

```bash
php bin/console app:user:create client@example.com "mot-de-passe" --client=<slug>
```

### Personnaliser la page de paiement (Stripe)

Fiche client → **Personnaliser** : couleurs, style d'écriture (4), forme des coins (3), fond de page (dégradé animé, formes flottantes, uni), titre, texte d'introduction, libellé du bouton (`{montant}` est remplacé), message de succès, ligne d'aide, étape de confirmation facultative et montants rapides. Le logo est celui de la fiche client. Tout est facultatif et validé (liste blanche, couleurs `#RRGGBB`, texte brut) ; la vue `pay_client_view` (migration `Version20261116140000`) expose ces valeurs à cyllos-pay, qui les applique sans script ni style en ligne.

### Mettre à jour le site de paiement depuis le back-office

Page **Mises à jour** : une seconde carte « Site de paiement (Cyllos-Pay) » compare la version déployée sur le serveur du site B à GitHub et propose **Mettre à jour Cyllos-Pay**. Cyllos envoie une requête signée (HMAC, `PAY_INTERNAL_SECRET`, comme pour les paiements) à `PAY_SITE_BASE_URL/internal/update/*` ; le site B lance alors `deploy/self-update.sh` (git pull, composer install, vidage du cache) sous son propre utilisateur, en tâche de fond, et la page affiche l'avancement et le journal. Ordre à respecter : mettre à jour Cyllos d'abord (migrations), puis Cyllos-Pay.

## Tâches planifiées

Trois tâches sont enregistrées via Symfony Scheduler dans
`src/Scheduler/AppSchedule.php` : rattrapage HelloAsso toutes les 10 min
(`app:helloasso:fetch`, qui distribue le travail sur la file `async`), purge des
paiements crédités toutes les 6 h (`app:payments:purge`), purge du journal
d'activité chaque nuit à 3h30 (`app:activity-log:purge`). Le planning est
`stateful` avec `processOnlyLastMissedRun(true)` : après un arrêt du worker,
chaque tâche manquée est rattrapée **une seule fois** au redémarrage, pas une
fois par créneau sauté.

**Important : le Scheduler ne "tourne" pas tout seul.** Les expressions cron ne
sont évaluées que par un worker qui reste actif en continu et consomme le
transport `scheduler_default` ; un second worker consomme la file `async`
(crédit Cyclos des paiements webhook, rattrapage par client, e-mails) :

```bash
php bin/console messenger:consume scheduler_default
php bin/console messenger:consume async
```

Si ces process ne tournent pas, aucune tâche planifiée ne se déclenche et les
paiements reçus par webhook ne sont pas crédités — ce ne sont pas des services
démarrés automatiquement par PHP ou Symfony. En production, ils doivent être
supervisés pour redémarrer en cas de crash (unités systemd avec
`Restart=always`, cf. `docs/DEPLOIEMENT_DEBIAN13.md`).

## Tests & qualité de code

```bash
php bin/phpunit           # tests (unitaires + fonctionnels — nécessite la base de test)
composer phpstan          # analyse statique (PHPStan niveau 6, baseline dans phpstan-baseline.neon)
composer cs               # style de code (PHP-CS-Fixer, --dry-run)
composer cs-fix           # applique le style
```

Le workflow GitHub Actions `.github/workflows/ci.yml` rejoue tout cela (plus
`lint:twig` / `lint:yaml` / `lint:container` et `doctrine:schema:validate`) sur
chaque *push* `main` et *pull request*, avec un service MariaDB pour les tests
fonctionnels.

`phpstan-baseline.neon` gèle les avertissements pré-existants ; tout nouveau code
est analysé au niveau 6. Le style suit PSR-12 (sans conditions Yoda, pour rester
cohérent avec l'existant).

## Déploiement en production

Procédure pour un serveur **Debian 12 (Bookworm)** ou **Ubuntu 24.04 LTS**. Les
commandes sont identiques sur les deux distributions sauf mention explicite
d'une différence.

> Pour une machine **Debian 13 (Trixie)** vierge (rien d'installé), voir le
> guide dédié et détaillé : [docs/DEPLOIEMENT_DEBIAN13.md](docs/DEPLOIEMENT_DEBIAN13.md).

### 1. Paquets système

MariaDB, Nginx, Git, Composer et les extensions PHP requises :

```bash
sudo apt update
sudo apt install -y mariadb-server nginx git unzip curl \
    php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring php8.4-xml \
    php8.4-curl php8.4-intl php8.4-opcache php8.4-zip
```

**Cyllos exige PHP ≥ 8.4** (`composer.json` : `"php": ">=8.4"` ; le `composer.lock`
verrouille des paquets Symfony qui réclament `>=8.4.1`). Ni Debian 12, ni Ubuntu
24.04 LTS ne fournissent PHP 8.4 dans leurs dépôts officiels par défaut : un
dépôt tiers est nécessaire dans les deux cas.

**Différence Debian/Ubuntu — dépôt PHP tiers :**
- **Debian 12** : dépôt [Sury](https://packages.sury.org/) :
  ```bash
  sudo apt install -y apt-transport-https lsb-release ca-certificates
  curl -fsSL https://packages.sury.org/php/apt.gpg | sudo tee /etc/apt/trusted.gpg.d/php.gpg >/dev/null
  echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" | sudo tee /etc/apt/sources.list.d/php.list
  sudo apt update
  ```
- **Ubuntu 24.04** : PPA `ondrej/php` (même mainteneur que Sury, packagé
  différemment pour Ubuntu) :
  ```bash
  sudo apt install -y software-properties-common
  sudo add-apt-repository -y ppa:ondrej/php
  sudo apt update
  ```

Composer, si absent des dépôts :

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
```

### 2. Base de données

```bash
sudo mysql -u root -e "
CREATE DATABASE cyllos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'cyllos'@'localhost' IDENTIFIED BY 'un-mot-de-passe-solide';
GRANT ALL PRIVILEGES ON cyllos.* TO 'cyllos'@'localhost';
FLUSH PRIVILEGES;
"
```

### 3. Utilisateur système et récupération du code

L'application tourne sous un utilisateur dédié (jamais `root`), membre du
groupe du serveur web pour que Nginx puisse lire les fichiers publics :

```bash
sudo adduser --system --group --home /var/www/cyllos cyllos
sudo usermod -aG cyllos www-data
sudo -u cyllos git clone https://github.com/CylaosICT/Cyllos.git /var/www/cyllos
cd /var/www/cyllos
```

Si le bouton "Déployer la mise à jour" de `/dev/version` doit fonctionner
(voir plus haut), l'utilisateur qui exécute PHP-FPM (`www-data` par défaut)
doit lui-même avoir les droits d'écriture sur ce dossier et l'accès à `git`
et `composer` dans son `PATH` — le plus simple est de faire tourner le pool
PHP-FPM sous l'utilisateur `cyllos` plutôt que `www-data` (voir la directive
`user`/`group` du pool à l'étape 6).

### 4. Dépendances et configuration

```bash
sudo -u cyllos composer install --no-dev --optimize-autoloader --no-interaction
sudo -u cyllos cp .env .env.local
```

Éditer `.env.local` (jamais commité) :

```
APP_ENV=prod
APP_SECRET=<généré avec: php -r "echo bin2hex(random_bytes(16));">
DATABASE_URL="mysql://cyllos:un-mot-de-passe-solide@127.0.0.1:3306/cyllos?serverVersion=10.11.2-MariaDB&charset=utf8mb4"
MAILER_DSN=smtp://... # DSN réel du serveur d'envoi
GITHUB_UPSTREAM_REPO=CylaosICT/Cyllos
GITHUB_UPSTREAM_BRANCH=main
```

Générer et renseigner la clé de chiffrement des secrets HelloAsso/Cyclos :

```bash
sudo -u cyllos php bin/console app:generate-encryption-key
# copier la sortie dans .env.local : APP_ENCRYPTION_KEY=...
```

### 5. Base de données, cache, assets

```bash
sudo -u cyllos php bin/console doctrine:migrations:migrate --no-interaction --env=prod
sudo -u cyllos php bin/console cache:clear --env=prod
sudo -u cyllos php bin/console asset-map:compile --env=prod
sudo -u cyllos php bin/console app:user:create admin@cylaos.example "un-mot-de-passe-solide" --admin
```

### 6. PHP-FPM

Créer un pool dédié `/etc/php/8.4/fpm/pool.d/cyllos.conf` (le chemin
`8.4` est identique sur les deux distributions une fois le paquet installé) :

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
```

### 7. Nginx

`/etc/nginx/sites-available/cyllos` :

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

```bash
sudo ln -s /etc/nginx/sites-available/cyllos /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

Certificat HTTPS (Let's Encrypt, identique sur les deux distributions) :

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d cyllos.exemple.fr
```

### 8. Worker du Scheduler (rattrapage + purge)

Sans ce service actif en permanence, `app:helloasso:fetch` et
`app:payments:purge` ne se déclenchent jamais (voir
[Tâches planifiées](#tâches-planifiées)). Unité systemd
`/etc/systemd/system/cyllos-scheduler.service` :

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

`--time-limit=3600` fait sortir le worker proprement toutes les heures ;
`Restart=always` le relance immédiatement — évite qu'une fuite mémoire
éventuelle s'accumule indéfiniment sur un process qui ne redémarre jamais.

### 9. Mises à jour ultérieures

Manuellement :

```bash
cd /var/www/cyllos
sudo -u cyllos git pull --ff-only
sudo -u cyllos composer install --no-dev --optimize-autoloader --no-interaction
sudo -u cyllos php bin/console doctrine:migrations:migrate --no-interaction --env=prod
sudo -u cyllos php bin/console cache:clear --env=prod
sudo systemctl restart php8.4-fpm cyllos-scheduler
```

Ou via le bouton "Déployer la mise à jour" de `/dev/version`
(`ROLE_DEVELOPER`/`ROLE_CEO`), qui exécute les quatre premières étapes automatiquement — voir
[Vérification de version](#vérification-de-version). Il ne redémarre pas
PHP-FPM ni le worker Scheduler ; un `cache:clear` suffit dans la plupart des
cas, mais un redémarrage manuel du worker reste nécessaire après une
modification du code du Scheduler lui-même.
