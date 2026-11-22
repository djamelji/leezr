# DEV · compte Platform de recette en lecture seule (ADR-862, ADR-863)

**Pourquoi** : le seul compte Platform de DEV est le compte personnel n° 1 du propriétaire. L'agent ne l'utilise pas, ne demande pas son mot de passe et ne contourne aucun contrôle d'accès. La recette Platform 5.0 demande donc un compte dédié, temporaire, **sans aucun droit d'écriture**.

**Prérequis** : le lot ADR-863 doit être déployé sur DEV, car il apporte les droits `view_jobdomains`, `view_modules` et `view_document_catalog`. Sans lui, JobDomains, Modules et Catalogues n'ont qu'un droit de gestion. Le déploiement est un GO distinct.

**Qui crée le compte** : le propriétaire, depuis l'interface Platform, après un GO distinct. Créer un rôle ou un utilisateur exige `manage_roles` et `manage_platform_users`, que seul le compte n° 1 possède. L'agent ne crée rien. Pas de super-admin, et aucune modification des rôles `super_admin` et `admin`. Les nouveaux droits de lecture ne sont proposés automatiquement à aucun rôle historique.

## 1. Rôle « Recette lecture » (12 droits, tous en lecture)

Dans **Gestion des accès › Rôles**, créer le rôle `recette_lecture` avec ces droits :

| Page V35 | Permission |
|---|---|
| Tableau de bord, indicateurs financiers | `view_dashboard`, `view_billing` |
| Alertes | `view_alerts` |
| Entreprises et leurs utilisateurs | `view_companies`, `view_company_users` |
| Supervision, journal | `view_usage_monitoring`, `view_system_health`, `view_audit_logs` |
| JobDomains, modèles de contrat, presets d'organisation | `view_jobdomains` |
| Presets versionnés | `presets.view` |
| Modules | `view_modules` |
| Catalogue documentaire | `view_document_catalog` |

À ne jamais accorder : tout droit `manage_*`, `create_companies`, `presets.edit`, `presets.publish`, `exceptional_company_export`.

Comportement prouvé sur le clone (ADR-863) :

- Le compte lit toutes ces pages, sur ordinateur et sur mobile, en clair et en sombre.
- Il ne voit **aucun** bouton d'écriture et aucun interrupteur actif.
- Toute écriture qu'il tenterait par l'API est refusée (403).
- Utilisateurs et Rôles ne lui montrent que le journal.

## 2. Utilisateur de recette

Dans **Gestion des accès › Utilisateurs** :

1. Créer `recette-platform@leezr-test.invalid`. Ce domaine ne reçoit pas de courrier.
2. Décocher l'invitation : DEV n'envoie pas d'e-mails.
3. Saisir un mot de passe **généré par le gestionnaire** de mots de passe.
4. Attribuer **uniquement** le rôle `recette_lecture`.
5. Transmettre le mot de passe à l'agent **par fichier, jamais dans le chat** :
   - le propriétaire l'écrit dans un fichier `chmod 600` hors du dépôt, et donne seulement le chemin ;
   - l'agent le lit par variable d'environnement, ne l'affiche jamais, et supprime le fichier à la fin de la recette.

## 3. Recette

L'agent rejoue, en lecture seule, les écrans suivants :

- tableau de bord V35 ;
- indicateurs financiers ;
- alertes ;
- navigation ;
- Facturation ;
- JobDomains ;
- Modules ;
- Catalogues.

Il vérifie :

- l'ordinateur et le mobile, en clair et en sombre ;
- l'absence d'erreur JavaScript et de refus API inattendu ;
- qu'aucune requête d'écriture n'est envoyée.

## 4. Révocation, le jour même

1. **Utilisateurs** › compte de recette › **Supprimer**. Ses sessions deviennent inutilisables : la connexion répond 401, ce qui est prouvé sur le clone.
2. **Rôles** › `recette_lecture` › supprimer le rôle.
3. Contrôle, que l'agent peut lancer en lecture seule :

   ```
   ssh leezr-dev 'bash -s' < docs/bmad/leezr-5-design/release/platform1-check.sh
   ```

   Résultat attendu : `other platform accounts=0`.
