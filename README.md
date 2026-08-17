# **Proprietary Software — Production use requires written authorization from Cylaos ICT.**

# Cyllos

Cyllos écoute les paiements HelloAsso de plusieurs clients (monnaies locales) et
crédite automatiquement leur compte Cyclos correspondant. C'est une réécriture
multi-tenant d'[Hellos](https://github.com/jymaire/hellos), sous Symfony : une seule
application gère l'ensemble des clients, chacun avec ses propres identifiants
HelloAsso et sa propre connexion Cyclos.

## Fonctionnement

### Modèle multi-tenant

Une seule instance de Cyllos gère tous les clients Cylaos. Chaque `Client` porte
un slug unique et possède sa propre configuration :
- `HelloAssoConfig` : identifiants API HelloAsso (client ID/secret chiffré),
  organisation, formulaire ciblé, montant maximum autorisé par paiement ;
- `CyclosConfig` : URL de l'instance Cyclos, utilisateur technique et mot de
  passe (chiffré), groupes Cyclos "pro"/"particulier" et types d'émission
  associés ;
- `ClientSetting` : paiements Cyclos activés ou non (mode "aperçu" sinon),
  crédit automatique activé ou non, email de notification, notification de
  l'association à chaque paiement.

Les secrets (`clientSecret` HelloAsso, mot de passe Cyclos) sont chiffrés en
base avec `APP_ENCRYPTION_KEY` via `SecretEncryptor` — jamais stockés en clair.

### Cycle de vie d'un paiement

1. **Réception** : HelloAsso notifie Cyllos par webhook
   (`POST /webhook/helloasso/{slug}`) à chaque paiement. `PaymentProcessor`
   valide la notification (bon formulaire, montant sous la limite, état
   `Authorized`/`Waiting`, pas de doublon) et crée un `Payment`.
2. **Décision** :
   - si le crédit automatique est désactivé pour ce client → le paiement reste
     `Todo`, à créditer manuellement depuis `/admin` ou l'espace client ;
   - si le paiement est trop en retard (> 12h, `NUMBER_LATE_HOURS_ACCEPTED`) ou
     encore `Waiting` côté HelloAsso → il est marqué en conséquence et un mail
     d'alerte est envoyé, sans crédit automatique ;
   - sinon, `PaymentProcessor` tente immédiatement de créditer le compte
     Cyclos correspondant.
3. **Crédit Cyclos** (`CyclosClient`) : recherche de l'utilisateur par email
   (avec repli sur un email alternatif récupéré via l'API HelloAsso si
   introuvable), détermination du type d'émission selon son groupe Cyclos,
   vérification anti-doublon (un paiement avec la même description n'a pas
   déjà été crédité), puis exécution du paiement — ou simple `preview` si les
   paiements Cyclos sont désactivés pour ce client (`PreviewOk`).
4. **Rattrapage** : en complément du webhook temps réel, `app:helloasso:fetch`
   interroge l'historique HelloAsso de chaque client actif pour récupérer tout
   paiement manqué (notification perdue, HelloAsso indisponible, etc.), sans
   déclencher de crédit automatique — il reste `Todo` en attente d'une action
   manuelle. C'est ce mécanisme qui doit tourner via le Scheduler (voir plus
   bas) pour combler les trous.

Chaque paiement (`Payment`) garde un statut (`todo`, `too_high`, `too_late`,
`preview_ok`, `success`, `success_auto`, `fail`, `waiting`) et un message
d'erreur le cas échéant, visible dans les listes de paiements.

### Espaces applicatifs

- **`/admin`** (`ROLE_ADMIN`) : vue transverse sur tous les clients — gestion
  des clients (assistant de création en 4 étapes, config HelloAsso/Cyclos/
  réglages), tous les paiements avec filtre par client, crédit/suppression
  manuels, synchro HelloAsso à la demande, comptes utilisateurs par client,
  recherche globale.
- **`/app`** (`ROLE_CLIENT`) : espace self-service pour un client — liste de
  ses seuls paiements (isolation garantie par `ClientOwnsPaymentVoter`),
  crédit et suppression.
- **`/dev`** (`ROLE_DEVELOPER`) : journal d'activité (`ActivityLog`), qui trace
  les créations/modifications/suppressions d'entités sensibles et les
  évènements de connexion, via des listeners Doctrine et Security.
- **`/settings`** : self-service pour tout utilisateur connecté (thème clair/
  sombre, email, mot de passe).

### Rôles

- `ROLE_CLIENT` : accès à ses propres paiements uniquement.
- `ROLE_ADMIN` : accès global à `/admin` ; peut créer/gérer les clients et les
  comptes admin classiques, mais ne peut ni modifier ni supprimer un compte
  développeur ou CEO (visible en lecture seule dans `/admin/equipe`).
- `ROLE_DEVELOPER` (hérite de `ROLE_ADMIN`) : accès en plus au journal
  d'activité.
- `ROLE_CEO` (hérite de `ROLE_ADMIN` et `ROLE_DEVELOPER`) : seul rôle habilité
  à attribuer `ROLE_DEVELOPER` à la création d'un compte, à gérer les comptes
  développeur/CEO, et à activer/désactiver des comptes admin.

Un compte désactivé (`active = false`) ne peut plus se connecter (appliqué via
`AppUserChecker`, un `UserCheckerInterface` exécuté à l'authentification).

## Prérequis

- PHP 8.3+ avec les extensions `pdo_mysql` et `openssl`
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

Depuis `/admin/clients`, créer un client avec :
- ses identifiants HelloAsso (client ID/secret, organisation, formulaire) ;
- sa connexion Cyclos (URL, utilisateur technique, groupes/émissions) ;
- ses réglages (paiements Cyclos actifs, mode automatique, email de notification).

L'URL du webhook à renseigner côté HelloAsso ("Intégrations et API") est
affichée sur la page du client : `/webhook/helloasso/{slug}`.

Créer ensuite un compte pour ce client (accès limité à ses propres paiements) :

```bash
php bin/console app:user:create client@example.com "mot-de-passe" --client=<slug>
```

## Tâches planifiées

Deux tâches sont enregistrées via Symfony Scheduler dans `src/Scheduler/AppSchedule.php` :
rattrapage HelloAsso toutes les minutes (`app:helloasso:fetch`), purge des vieux
paiements chaque nuit à 3h (`app:payments:purge`).

**Important : le Scheduler ne "tourne" pas tout seul.** Les expressions cron ne
sont évaluées que par un worker qui reste actif en continu et consomme le
transport `scheduler_default` :

```bash
php bin/console messenger:consume scheduler_default
```

Si ce process ne tourne pas, aucune tâche planifiée ne se déclenche — ce n'est
pas un service en arrière-plan démarré automatiquement par PHP ou Symfony. En
production, il doit être supervisé pour redémarrer en cas de crash (unité
systemd avec `Restart=always`, service Supervisor, ou conteneur worker dédié
dans Docker) et rester actif en permanence.

## Tests

```bash
php bin/phpunit
```
