# Leezr 4.2 — M8 : fin de relation, export complet, résiliation (ADR-845)

Périmètre : FUN-01 (procédure de clôture et conservation), FUN-02 (export complet à la demande), supervision Platform.
Travail local sur `dev`, recette sur le clone marqué `leezr_audit842`, société fictive 106 seulement. Aucun push, aucun déploiement, aucune donnée supprimée.

## 1. Ce qui existait (audit)

| Mécanisme | Existant avant M8 | Réutilisé tel quel |
|---|---|---|
| Résiliation | `SubscriptionCanceller::cancel` (immédiate ou fin de période selon la politique Billing), aperçu `cancel-preview` | oui |
| Annulation d'une résiliation programmée | Platform uniquement, logique dans le contrôleur | logique **déplacée** dans `SubscriptionCanceller::undoScheduled`, appelée par Company et Platform |
| Fin d'abonnement | `billing:renew` passe l'abonnement à `cancelled` ; la société reste active ; mode restreint `EnsureBusinessAccess` (lecture permise, écritures métier bloquées en 402) | oui, une entrée ajoutée à la liste blanche : `api/company/data-exports` |
| Suspension | `companies.status = suspended` (Platform), tout est bloqué côté Company | oui |
| Exports | export salariés, export temps M7 (`TimeExportService`) | M7 réutilisé à l'identique (`writeCsv`) |
| Archivage, suppression définitive, conservation par pays | **inexistants** | non créés (voir §5) |

## 2. Parcours avant / après

**Avant** : la société pouvait résilier depuis l'onglet Vue d'ensemble, sans voir ce que la résiliation implique pour ses données. Elle ne pouvait ni annuler cette résiliation ni récupérer ses données en une fois. Platform ne voyait rien de cela.

**Après**, avec un onglet « Données et fin de contrat » dans Abonnement & factures (adresse `/company/billing/closure`) :

1. **Abonnement** : état (Actif, En essai, Résiliation programmée, Abonnement terminé…), offre, date de fin.
2. **Effets et dates** : date d'effet ; accès maintenu ; données non supprimées ; conservation « non définie pour votre pays, aucune suppression automatique ».
3. **Export** : bouton « Préparer un export complet ». L'export est réservé au propriétaire et demande la saisie du mot de passe ; l'archive est construite en tâche de fond ; un e-mail est envoyé quand elle est prête ; elle se télécharge depuis l'application, connecté.
4. **Points à vérifier** : factures impayées (avec lien), paies calculées ou validées non exportées, export disponible ou conseillé. Ces points informent sans bloquer.
5. **Confirmation** : boîte de résiliation existante (aperçu, puis confirmation).
6. **Transitions** : effet immédiat ou fin de période, selon la politique Billing existante.
7. **Personnes informées** :
   - e-mail « export prêt » au demandeur ;
   - les e-mails Billing existants de résiliation ne sont pas modifiés.
8. **Journal** :
   - `company_export.requested`, `company_export.ready`, `company_export.failed`, `company_export.downloaded`, `company_export.revoked` ;
   - `subscription.cancel_undone` ;
   - plus les entrées Billing existantes.
9. **Supervision Platform** :
   - carte « Fin de contrat et données » dans l'onglet facturation de la fiche entreprise : état commercial, accès, conservation, points ouverts, exports (état, téléchargements, expiration) ;
   - révocation d'un lien (`manage_companies`) ;
   - segment « Fin de contrat » dans la liste Entreprises : résiliation programmée, ou export en cours ou disponible.

Annulation : le bouton « Annuler la résiliation » est visible tant que la résiliation programmée n'a pas pris effet. Après une résiliation immédiate, il faut un nouvel abonnement.

## 3. Données exportées / non exportées

**Archive ZIP** (CSV UTF-8 avec BOM, séparateur `;`, un fichier `manifest.json`). Exemple réel : `exemple-export.zip`, `exemple-manifest.json`.

| Fichier | Contenu |
|---|---|
| `company.json` | identité, adresse, SIRET/NAF, marché, offre, modules actifs |
| `members.csv` | utilisateurs rattachés, rôle, état d'accès |
| `workforce/employees.csv` | fiche RH (matricule, nom, contact, statut, dates, service, poste) |
| `workforce/contracts.csv` | métadonnées des contrats |
| `workforce/time/{entries,breaks,daily,planning,leaves}_{année}.csv` | exports M7, toute l'historique, un fichier par année |
| `logistics/shipments.csv` | expéditions |
| `billing/invoices.csv`, `billing/subscriptions.csv` | factures Leezr, historique des abonnements |
| `audit/company_audit_log.csv` | journal d'audit (date, action, auteur, objet, gravité) |
| `documents/…` + `documents/index.csv` | version courante des documents du personnel, documents société, documents générés, bulletins publiés non révoqués |

**Jamais exportés** (listés dans `manifest.exclusions`) :
- mots de passe, secrets de double authentification, sessions, jetons d'API ;
- adresses IP et navigateurs du journal ;
- versions antérieures des documents ;
- fichiers DSN ;
- fichiers en quarantaine antivirus ;
- données d'une autre société.

Documents au-delà du budget de taille (500 Mo par défaut) : listés en `over_size_budget` dans l'index et signalés dans les exclusions, jamais omis silencieusement.

**Échec partiel** : un jeu de données en erreur n'empêche pas l'archive d'être produite. Le manifeste le signale (`errors: [{part, reason}]`) et la page l'affiche (« Incomplet : … »).

## 4. Contrôles de sécurité

- **Droit** : propriétaire de l'entreprise seulement, à l'intérieur du groupe Facturation (`billing.manage`), donc deux contrôles. Aucune nouvelle permission RBAC.
- **Identité** : ressaisie du mot de passe pour chaque demande ; limiteur de 5 demandes par 10 minutes, message traduit en cas d'excès.
- **Concurrence** : une seule préparation à la fois par société, sous verrou de ligne sur la société ; puis un délai de 10 minutes entre deux demandes.
- **Isolement** :
  - toutes les requêtes filtrent `company_id` ;
  - téléchargement d'un export d'une autre société : 404 ;
  - le chemin de stockage n'est jamais renvoyé ;
  - Platform ne peut pas télécharger l'archive.
- **Téléchargement** :
  - authentifié, aucun lien public, `Cache-Control: no-store` ;
  - compteur et journal à chaque téléchargement ;
  - limiteur de 10 par minute.
- **Expiration** : 7 jours (`COMPANY_EXPORT_TTL_DAYS`), puis 410. La tâche planifiée `company-exports:prune` (quotidienne, 03:30, inscrite au registre des automatisations) supprime le fichier d'archive et garde la ligne comme trace.
- **Révocation** depuis Platform : le fichier est supprimé et le lien passe en 410.
- **Tâche** : `BuildCompanyDataExportJob` tourne sur la file existante, en une seule tentative ; un échec passe l'export en « échec » et le journalise. Billing async n'est pas touché.

## 5. Décisions juridiques et commerciales à valider

| # | Décision | État actuel |
|---|---|---|
| D-M8-1 | Durées de conservation par marché (factures, bulletins, contrats, journal) | `config/company_lifecycle.retention.markets` **vide** ; aucune durée légale inventée ; rien n'est supprimé |
| D-M8-2 | Suppression définitive des données d'une société | **non implémentée**, nécessite une décision séparée, documentée et testée |
| D-M8-3 | Statut « archivée » | non supporté par le modèle ; non créé (pas de besoin démontré) |
| D-M8-4 | Qui peut exporter : propriétaire seul, ou un droit délégable | propriétaire seul |
| D-M8-5 | Durée de disponibilité de l'archive | 7 jours, réglable |
| D-M8-6 | Export pendant une suspension | **résolu M8.1 (ADR-846)** : seul le parcours d'export s'ouvre |
| D-M8-7 | Budget de taille d'une archive et procédure de complément | 500 Mo, complément sur demande au support |
| D-M8-8 | Accès après fin d'abonnement | lecture et export maintenus, sans limite de durée tant que D-M8-1 n'est pas tranchée |

## 6. Tests

- **Backend** : `tests/Feature/Adr845CompanyLifecycleTest.php`, 8 tests :
  - états issus des données existantes ;
  - contenu de l'archive lu fichier par fichier, avec isolement et absence de secrets ;
  - propriétaire seul, mot de passe, export unique et délai entre deux demandes ;
  - téléchargement réservé, limité dans le temps et cantonné à la société, expiration puis purge sans perte de données ;
  - échec partiel ;
  - résiliation programmée puis annulée, et supervision Platform (lecture, segment, révocation sur droit) ;
  - résiliation immédiate : accès, export et factures conservés ;
  - export volumineux (budget de documents).
- **Recette navigateur** : `tests/e2e/audit842-m8-recipe.spec.js`, 2 tests, rejouable (fixture `scripts/audit842-m8-fixture.php`). L'archive réellement téléchargée est ouverte (`unzip`) et lue. Captures `apres-*.jpg`.

---

# M8.1 — Récupération fiable des données (ADR-846)

## 1. Export pendant une suspension

- **Mécanisme réutilisé** : la liste d'adresses ouvertes aux sociétés suspendues (`SetCompanyContext::SUSPENDED_BYPASS_PREFIXES`, qui contenait déjà la facturation et le support). On y ajoute deux chemins exacts : `api/company/lifecycle` et `api/company/data-exports`.
- **Contrôles inchangés** :
  - session valide ;
  - groupe Facturation (`billing.manage`) ;
  - propriétaire et ressaisie du mot de passe pour demander ;
  - propriétaire ou destinataire désigné pour télécharger.
- **Rien n'est réactivé** : la société reste suspendue et l'abonnement inchangé. C'est vérifié par les tests et par la recette.
- **Toujours fermés pendant la suspension**, vérifiés par l'API en recette :
  - Expéditions, Membres, Services Workforce, Modules : 403 ;
  - une écriture métier : 403.
- **Sans session** : 401, l'archive ne sort pas.
- **Interface** : un bandeau sur la page « Données et fin de contrat » indique la suspension et ce qui reste accessible.

## 2. Export complet, partiel ou échoué

| Statut | Signification | Téléchargeable |
|---|---|---|
| `ready` (« Export complet ») | aucune donnée manquante | oui |
| `partial` (« Export partiel ») | archive produite, mais `manifest.outcome = partial` et `manifest.missing` non vide | oui, bouton « Télécharger l'export partiel » |
| `failed` (« Échec ») | aucune archive exploitable (stockage indisponible, toutes les parties en échec) | non (410) |

**Motifs de données manquantes**, chacun avec la partie concernée et un nombre :
- `generation_failed` : un module en erreur ;
- `over_size_budget` : document au-delà du budget de taille ;
- `missing_file` : fichier absent du stockage.

**Affichage** : la liste figure dans la page **avant** le bouton de téléchargement. Elle est reprise dans `manifest.json`, dans l'e-mail (texte « PARTIEL ») et dans le journal (`result = partial`, `missing`).

**Exclusions de principe** (secrets, adresses IP, versions antérieures, DSN, quarantaine) : elles restent documentées dans `manifest.exclusions` et ne rendent pas un export partiel, car elles ne sont pas des données manquantes.

**En cas d'échec** : la nouvelle archive incomplète est supprimée. Les archives précédentes et les données restent intactes, ce qui est testé.

Exemple réel : `m81/exemple-export-partiel.zip` et `m81/exemple-manifest-partiel.json`.

## 3. Procédure exceptionnelle (propriétaire indisponible)

Le propriétaire reste le **seul demandeur ordinaire**. La procédure Platform se déroule ainsi :

1. Permission dédiée `exceptional_company_export`. Elle ne fait partie d'aucun groupe de permissions et doit être attribuée explicitement.
2. Demande : destinataire choisi parmi les membres actifs de la société, justification d'au moins 30 caractères, description de la vérification de l'autorité du représentant. Le statut devient `awaiting_approval`. Une seule demande à la fois.
3. Validation par un **second** opérateur titulaire de la même permission. Le demandeur ne peut pas valider sa propre demande : le bouton est désactivé et l'API répond 422. Le second opérateur peut aussi refuser (`rejected`).
4. Journal : `company_export.exceptional_requested`, `_approved`, `_rejected`, `_blocked`, gravité critique, côté Platform **et** côté société.
5. Remise : l'archive est produite pour le membre désigné, qui reçoit un e-mail et la télécharge depuis son espace Company, connecté. **Aucune route de téléchargement Platform** n'existe (404/405, vérifié).
6. **Exécution désactivée** (`COMPANY_EXCEPTIONAL_EXPORT_ENABLED=false`). L'autorité d'un représentant (identité et mandat) ne peut pas être vérifiée de façon fiable par le produit aujourd'hui. Les demandes sont donc enregistrées et journalisées, mais une validation répond 409 et aucune archive n'est produite. Le chemin complet, avec exécution activée, est couvert par les tests PHP.

## 4. Tests M8.1

**Backend** — `tests/Feature/Adr846CompanyDataRecoveryTest.php`, 6 tests :
- société suspendue et propriétaire ;
- société suspendue et membre non autorisé ;
- export complet, partiel par budget de taille et partiel par module en échec et fichier absent ;
- échec total ;
- lien révoqué, absence de session et autre société ;
- procédure exceptionnelle : permission, justification, destinataire, auto-validation refusée, 409 tant que l'exécution est désactivée, puis exécution, remise au destinataire désigné, absence de route Platform, refus.

**Recette navigateur** — `tests/e2e/audit842-m81-recipe.spec.js`, 2 tests, rejouée avec la recette M8 : 4 réussites sur 4, deux fois. Les archives réellement téléchargées sont ouvertes et lues. Captures dans `m81/apres-*.jpg`.

## 5. Risques résiduels et décisions

**Risques résiduels :**
- **R-M81-1** (**corrigé M8.2, ADR-847**) : société suspendue, l'interface affiche une notification brute en anglais (« This company is currently suspended. ») issue des autres appels de l'application. Ce comportement est antérieur à M8.1 et la page de récupération reste utilisable. Correction à décider : une gestion globale du 403 de suspension.
- **R-M81-2** (**corrigé M8.2, ADR-847** ; défaut latent découvert pendant la recette) : le journal Platform copie la clé du rôle dans `platform_audit_logs.actor_type`, limitée à 20 caractères. Un rôle Platform dont la clé dépasse 20 caractères empêche donc la connexion de ses titulaires (500). Ce défaut est hors périmètre et n'est pas corrigé ; la recette utilise une clé courte.
- **R-M81-3** : une société suspendue peut lancer des exports dans la limite de 5 par 10 minutes, avec 10 minutes entre deux demandes. La charge est donc bornée, mais pas nulle.

**Décisions à valider :**
- **D-M81-1** : quels justificatifs d'autorité accepter (mandat, extrait Kbis, jugement, pièce d'identité) et qui les contrôle, avant d'activer l'exécution des exports exceptionnels.
- **D-M81-2** : le destinataire d'un export exceptionnel doit-il pouvoir être une personne extérieure (liquidateur, héritier) ? Aujourd'hui c'est impossible par conception : seul un membre de la société peut le recevoir, ce qui évite tout lien public.

---

# M8.2 — Connexion Platform et mode Company suspendue (ADR-847)

## Causes

**R-M81-2 — connexion Platform en erreur 500**
- Le journal Platform écrivait la **clé du rôle** de l'opérateur dans `platform_audit_logs.actor_type`, une colonne de 20 caractères prévue pour une catégorie (admin, system, webhook).
- Toute clé de rôle de plus de 20 caractères faisait échouer chaque action journalisée, connexion comprise.
- Reproduit avant correction sur le clone MySQL : rôle fictif de 40 caractères, connexion en 500. Après correction : 200.

**R-M81-1 — mode Company suspendue**
- L'application chargeait au démarrage six ressources refusées à une société suspendue : navigation, modules, métier, espace personnel, notifications, temps réel. Le serveur répondait par un message brut en anglais.
- **Défaut plus grave découvert pendant la correction** : après une reconnexion à froid, les modules n'étaient pas chargés. La Facturation paraissait inactive et la page de récupération était renvoyée vers « module indisponible ». Le mode suspendu était donc inutilisable ; la recette M8.1 passait seulement parce que les modules avaient été chargés avant la suspension.

## Correctifs

**Journal Platform**
- Nouvelle colonne `actor_role` (255 caractères, migration additive). `actor_type` redevient une catégorie contrôlée ; une valeur inconnue est une erreur explicite, jamais tronquée.
- Historique conservé : rien n'est réécrit, et la clé de rôle est **copiée** dans `actor_role`.
- Lecteurs adaptés : fil d'activité, export CSV, onglet Journaux.
- L'en-tête navigateur (texte libre envoyé par le client) est borné à 500 caractères **et signalé** dans les métadonnées.

**Mode Company suspendue**
- `/my-companies` expose `suspended`. Le démarrage ne demande plus aucune ressource refusée ; menu réduit (« Données et fin de contrat », « Support ») ; routeur limité à Facturation, Support et 403.
- Page 403 « Entreprise suspendue » pour un membre sans droit.
- Un 403 de suspension affiche un seul message traduit.
- Réactivation : redémarrage complet de l'application.
- Le serveur reste l'autorité : toutes les autres routes répondent toujours 403, vérifié en recette.

## Recette navigateur (`tests/e2e/audit842-m82-recipe.spec.js`)

| # | Contrôle | Résultat |
|---|---|---|
| 1 | Connexion Platform avec une clé de rôle de 40 caractères | OK (auparavant 500) |
| 2 | Connexion super administrateur, lecteur, rôle court | OK |
| 3 | Journal : `admin · recette847_role_avec_une_cle_tres_longue`, rôle complet | OK |
| 4 | Permissions respectées (suspendre et journaux refusés, page 403) | OK |
| 5 | Société suspendue : connexion à froid vers la page de récupération, export téléchargé et lu | OK |
| 6 | Aucune requête refusée, aucun message brut (connexion, navigation, actualisation, reconnexion) | OK |
| 7 | Autres modules : écrans renvoyés vers la récupération ; API toujours en 403 | OK |
| + | Membre sans droit : état « Entreprise suspendue » ; réactivation : menu complet rétabli | OK |

Captures dans `m82/apres-*.jpg`.
