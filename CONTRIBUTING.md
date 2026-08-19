# Contribuer à Cyllos

Cyllos est un logiciel propriétaire de Cylaos ICT — ce dépôt n'accepte pas de
contributions externes non sollicitées. Ce guide s'adresse à l'équipe interne
et aux prestataires autorisés à travailler sur le projet.

## Avant de commencer

- Lis le [README.md](README.md) pour le fonctionnement général et
  l'installation.
- Une fois l'application lancée, la page **Documentation**
  (`/dev/documentation`, réservée `ROLE_DEVELOPER`) détaille l'architecture,
  les choix techniques, et un historique des incidents déjà résolus — à lire
  avant de modifier une zone que tu ne connais pas encore, pour ne pas
  réintroduire un bug déjà corrigé.

## Style de code

- **PHP** : suit les conventions déjà en place dans `src/` (typage strict,
  classes en lecture seule pour les DTO, `#[Route]`/`#[IsGranted]` en
  attributs). Pas de commentaire qui répète ce que le code dit déjà — un
  commentaire n'a de valeur que s'il explique un choix non évident (une
  contrainte cachée, un contournement de bug précis).
- **Twig** : les formulaires suivent le pattern `data_class: null` (tableau
  associatif plutôt qu'entité) sauf quand le formulaire correspond
  directement à une entité Doctrine.
- **JS** : contrôleurs Stimulus uniquement (`assets/controllers/`), pas de
  framework front séparé. Symfony AssetMapper sert les fichiers tels quels —
  aucune étape de build à lancer après une modification CSS/JS.
- **Pas de sur-ingénierie** : une correction de bug n'a pas besoin de
  refactoring alentour ; une action ponctuelle n'a pas besoin d'une
  abstraction générique tant qu'un vrai second cas d'usage n'existe pas.

## Tests

```bash
php bin/phpunit
```

Toute nouvelle règle métier (validation, calcul, décision automatique)
mérite un test unitaire. Les tests fonctionnels utilisent le client HTTP
simulé de Symfony avec des identifiants HelloAsso/Cyclos factices — jamais
d'appel réseau réel dans la suite de tests automatisée.

## Migrations Doctrine

Après toute modification d'entité :

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate            # base de dev
php bin/console doctrine:migrations:migrate --env=test  # base de test
```

Les deux environnements doivent être migrés — sinon les tests fonctionnels
échouent avec des colonnes manquantes alors que le code applicatif est
correct. Relis toujours le fichier de migration généré avant de l'exécuter :
`doctrine:migrations:diff` peut réintroduire du SQL en double si une
migration précédente n'a pas encore été appliquée dans l'environnement où
la commande est lancée.

## Serveur de développement

Le serveur `php -S 127.0.0.1:8000 -t public` ne recharge pas les classes PHP
modifiées une fois démarré — un redémarrage est nécessaire après avoir
ajouté ou modifié une classe dans `src/`. Les modifications de templates
Twig, CSS ou JS n'ont pas besoin de redémarrage.

## Documentation

Toute modification de comportement (nouvelle fonctionnalité, changement de
règle métier, nouveau rôle) doit être répercutée **dans le même commit** :

- la section correspondante de `templates/dev/documentation/show.html.twig` ;
- le [README.md](README.md) si le changement affecte l'installation, la
  configuration ou le fonctionnement général décrit en tête de fichier.

Une fonctionnalité non documentée n'est pas considérée comme terminée.

## Commits

Message court à l'impératif, sans point final (`Add ...`, `Fix ...`,
`Prevent ...`) — voir l'historique du dépôt pour des exemples. Un commit
doit laisser le dépôt dans un état cohérent : tests qui passent, lint Twig
propre, documentation à jour.

## Sécurité

Avant de committer, vérifie qu'aucun secret (mot de passe, clé API,
`APP_ENCRYPTION_KEY`, jeton) n'est présent dans le code ou les fichiers de
configuration versionnés — voir [SECURITY.md](SECURITY.md) pour les règles
de gestion des secrets de l'application.
