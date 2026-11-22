# DEV · compte Platform de recette dédié (ADR-862)

**Pourquoi** : le seul compte Platform de DEV est le compte personnel n° 1 du propriétaire. L'agent ne l'utilise pas et ne cherche pas ses identifiants. La recette Platform 5.0 sur DEV demande donc un compte dédié, temporaire et limité.

**Qui crée le compte** : le propriétaire, depuis l'interface Platform. Créer un rôle ou un utilisateur exige `manage_roles` et `manage_platform_users`, que seul le compte n° 1 possède. L'agent ne crée rien sans autorisation distincte : pas de super-admin, et aucune modification des rôles existants (`super_admin`, `admin`).

## 1. Rôle « Recette lecture » (nouveau rôle, les rôles historiques restent intacts)

Dans **Rôles** (`/platform/roles`), le propriétaire crée un rôle `recette_lecture`.

Permissions minimales, en lecture seule :

| Page V35 | Permission |
|---|---|
| Tableau de bord, indicateurs financiers | `view_dashboard`, `view_billing` |
| Alertes | `view_alerts` |
| Sociétés et leurs utilisateurs | `view_companies`, `view_company_users` |
| Supervision, journal | `view_usage_monitoring`, `view_system_health`, `view_audit_logs` |
| Presets JobDomain (lecture) | `presets.view` |

**Limite connue** : les pages JobDomains, Modules et Catalogues ne sont gardées que par un droit de gestion. Il n'existe pas de droit « voir » pour elles. Il s'agit de `manage_jobdomains`, `manage_modules` et `manage_document_catalog`.

- Sans ces droits, ces pages restent non vérifiées sur DEV, et prouvées seulement sur clone.
- Pour les inclure, le propriétaire ajoute ces trois droits **pour la durée de la recette seulement**.
- L'agent s'engage à ne cliquer aucune action d'enregistrement.
- Le script de recette journalise toute requête d'écriture et s'arrête s'il en voit une.

À ne jamais accorder : `manage_platform_users`, `manage_platform_user_credentials`, `manage_roles`, `manage_billing`, `manage_companies`, `manage_maintenance`, `manage_session_settings`.

## 2. Utilisateur de recette

Dans **Utilisateurs** (`/platform/users`) :

1. Créer `recette-platform@leezr-test.invalid`. Ce domaine ne reçoit pas de courrier.
2. Décocher l'invitation : DEV n'envoie pas d'e-mails.
3. Saisir un mot de passe **généré par le gestionnaire** de mots de passe.
4. Attribuer **uniquement** le rôle `recette_lecture`.
5. Transmettre le mot de passe à l'agent **par fichier, jamais dans le chat** : un fichier `chmod 600` hors du dépôt, dont seul le chemin est donné. L'agent le lit par variable d'environnement et ne l'affiche jamais.

## 3. Recette

L'agent rejoue, en lecture seule, le parcours Platform :

- Pages : tableau de bord V35, indicateurs financiers, alertes, navigation, Facturation, JobDomains, Modules, Catalogues.
- Contrôles :
  - sur ordinateur et sur mobile, en clair et en sombre ;
  - pas d'erreur JavaScript, pas d'appel API refusé inattendu ;
  - les pages hors du rôle sont bien refusées.

## 4. Révocation, le jour même

1. **Utilisateurs** › compte de recette › **Supprimer**. Ses sessions et jetons disparaissent avec lui.
2. **Rôles** › `recette_lecture` › supprimer le rôle, ou au moins lui retirer les trois droits `manage_*` temporaires.
3. Contrôle, que l'agent peut lancer en lecture seule :

   ```
   ssh leezr-dev 'bash -s' < docs/bmad/leezr-5-design/release/platform1-check.sh
   ```

   Résultat attendu : `other platform accounts=0`.
