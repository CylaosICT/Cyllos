# Politique de sécurité

Cyllos est un logiciel propriétaire de Cylaos ICT qui synchronise des
paiements HelloAsso avec des comptes Cyclos (monnaie locale). Il manipule des
données financières et personnelles sensibles — toute vulnérabilité doit être
traitée avec sérieux et rapidité.

## Versions couvertes

Ce dépôt n'a qu'une seule ligne de développement active : la branche `main`.
Seule la dernière version déployée en production est couverte par cette
politique ; il n'y a pas de maintenance de versions antérieures.

## Signaler une vulnérabilité

**Ne pas** ouvrir une issue publique décrivant une faille de sécurité — cela
expose la vulnérabilité avant qu'un correctif soit disponible.

Contacter directement Cylaos ICT en interne (par un canal autre que le dépôt
public) pour tout signalement. Merci d'inclure :

- une description du problème et de son impact potentiel (accès non
  autorisé à des données client, contournement d'authentification, fuite de
  secrets, etc.) ;
- les étapes pour le reproduire ;
- si possible, la zone du code concernée.

Ce dépôt n'a pas de programme de bug bounty ; il ne s'agit pas d'un projet
ouvert à des tests d'intrusion non sollicités.

## Ce qui est considéré comme sensible dans ce projet

- **Secrets clients** : le `clientSecret` HelloAsso et le mot de passe
  technique Cyclos de chaque client sont chiffrés en base (AES-256-GCM, voir
  `SecretEncryptor`) et ne doivent jamais apparaître en clair dans les logs,
  le journal d'activité, ou les réponses HTTP journalisées.
- **`APP_ENCRYPTION_KEY`** : la clé de chiffrement de ces secrets. Elle ne
  doit exister que dans `.env.local` ou l'environnement de production,
  jamais committée, jamais partagée par un canal non chiffré.
- **Identifiants de connexion** : mots de passe hashés (bcrypt via
  `UserPasswordHasherInterface`), jamais stockés ni journalisés en clair.
- **Jetons de réinitialisation de mot de passe** : stockés en base sous
  forme de hash SHA-256 uniquement, jamais en clair.
- **Secrets TOTP (2FA)** : chiffrés en base au même titre que les secrets
  HelloAsso/Cyclos.
- **Données personnelles des payeurs** : noms, emails et montants des
  paiements HelloAsso traités par l'application.

## Mesures déjà en place

- Isolation multi-tenant applicative (`ClientOwnsPaymentVoter`) : un compte
  client ne peut accéder qu'aux données de son propre client.
- Hiérarchie de rôles stricte (`ROLE_CLIENT` < `ROLE_ADMIN` < `ROLE_DEVELOPER`
  < `ROLE_CEO`), avec des actions sensibles (vidage du journal, déploiement,
  gestion des comptes développeur/CEO) réservées aux rôles les plus élevés.
- Authentification à deux facteurs (TOTP) disponible en option pour tout
  compte.
- Réinitialisation de mot de passe en libre-service réservée aux comptes
  clients — jamais aux comptes admin/développeur/CEO, qui doivent être
  réinitialisés manuellement par un développeur ou le CEO.
- Protection CSRF (double-soumission sans état) sur les formulaires
  sensibles et l'authentification.
- Un journal d'audit (`ActivityLog`) trace les modifications d'entités
  sensibles, les connexions, et les appels API sortants — avec exclusion
  explicite des champs contenant des secrets ou des identifiants OAuth2.

## Ce que nous demandons en cas de signalement

- Ne pas exploiter la faille au-delà de ce qui est nécessaire pour la
  démontrer (pas d'accès, de modification ou d'exfiltration de données
  client réelles).
- Laisser un délai raisonnable pour corriger le problème avant toute
  divulgation, publique ou autre.
- Ne pas tester la vulnérabilité sur l'environnement de production sans
  autorisation préalable de Cylaos ICT.
