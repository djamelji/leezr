# ADR-813 — Finitions UX et hygiène DEV

- **Date** : 2026-11-17, DEV uniquement, base `2345b37c`.
- **Preuves** : `docs/bmad/adr813/` (captures, e-mail réel avec jeton masqué, inventaire de la file, registre de la société 101, outil d'isolement de la file).

## 1. Rôles par défaut (C4 d'ADR-811)

| Clé | Avant | Après (= preset « Logistique — rôles initiaux ») |
|---|---|---|
| owner | Propriétaire | Propriétaire |
| manager | Manager | Responsable |
| dispatcher | Dispatcher | Répartiteur |
| driver | Driver | Chauffeur-livreur |
| ops_manager | Operations Manager | Responsable d'exploitation |

- **Source** : `JobdomainRegistry` (code) et la définition du métier en base. Une migration non destructive, `2026_12_07_000001_logistique_default_role_names_fr`, renomme uniquement les noms anglais d'origine. Un nom modifié sur la Platform est conservé. Elle a un `down()`.
- **Sociétés existantes** : rien n'est touché.
  - `JobdomainGate::assignToCompany` applique le nom par défaut **à la création du rôle seulement**.
  - Avant, une réassignation du métier écrasait le nom, même personnalisé.
  - Clés, droits et `company_roles` sont inchangés. Les sociétés 1 à 101 gardent leurs noms.
- **Locales** : les noms de rôles sont des données de la société, pas des clés i18n. Aucune locale n'est modifiée.

## 2. E-mails

- **Cause** :
  - `APP_LOCALE=en` et aucune traduction française du gabarit Laravel : « Hello! », « Regards, », sous-texte du bouton, « All rights reserved. » et e-mail « mot de passe oublié ».
  - Les e-mails Billing et Onboarding traduits sortaient dans la langue de l'application, pas dans celle du destinataire.
- **Correction** (mécanismes Laravel existants) :
  - `lang/fr.json` traduit les chaînes du gabarit et de `ResetPassword`.
  - `User` et `PlatformUser` implémentent `HasLocalePreference`, via `App\Core\Notifications\MailLocale`.
  - Ordre de résolution de la langue :
    1. marché de la Company ;
    2. marché commun à toutes les sociétés du destinataire ;
    3. marché par défaut (FR) ;
    4. `app.locale`.
  - Seules les langues livrées (fr, en) sont retenues.
  - Candidat sans compte (Onboarding) : la langue est celle du marché de la société.
  - Les 5 notifications rédigées seulement en français fixent leur gabarit en `fr`. L'alerte critique Platform, rédigée en anglais, le fixe en `en`.
- **Testé** :
  - FR et EN (société GB) : mot de passe oublié, invitation et activation, notification traduite (code Onboarding) ;
  - au navigateur : e-mail réel « mot de passe oublié » reçu dans Mailpit, entièrement en français (`adr813/email-forgot-fr.jpg`).

## 3. P3

| P3 | Cause prouvée | Correction |
|---|---|---|
| Codes d'audit | `<code>{{ item.action }}</code>` | Libellé FR/EN pour les 165 codes connus (`audit.actions.*`). Le code reste dans le détail de la ligne (« Code technique »). Un code inconnu s'affiche tel quel. |
| Erreur console du graphique Platform | vue3-apexcharts 1.5.3 : un changement de `height`, `width` ou `type` détruit le graphique **sans le recréer**. Les widgets calculent leur hauteur après la mise en page, le graphique devenait vide et la mise à jour suivante levait `forceXAxisUpdate`. De plus, `destroy()` d'ApexCharts laisse son minuteur de redimensionnement de 150 ms, qui redessine l'instance détruite (« height: NaN »). | `LzrApexChart` : la hauteur et le type font partie de la clé, donc remontage propre, et le minuteur est annulé avant chaque remontage ou démontage. `LzrGauge` : clé sur la largeur et la hauteur. Ni `@core` ni la librairie ne sont modifiés. Aucune erreur console au chargement, au redimensionnement ni en quittant la page. |
| Formats acceptés (Mon espace) | Le libellé `documents.acceptedTypes` n'a pas de paramètre. Le défaut affiché (`pdf, jpg, png`) différait du défaut appliqué (`pdf, jpg, jpeg, png`). | Une seule source : `SubjectDocumentUploadService::acceptedTypes()`, utilisée par la validation et par les lectures. Affichage « Formats acceptés : PDF, JPG, JPEG, PNG ». |
| Bloc Documents de Mon activité | Il ne liste que les documents reçus, mais son titre « Mes documents et bulletins » laissait attendre les pièces à fournir. | Titre « Documents reçus ». L'état vide explique ce qui apparaîtra ici et renvoie vers « Tout voir » pour les pièces à fournir. |

Aucune page ajoutée.

## 4. File DEV (aucun traitement exécuté)

L'inventaire en lecture seule porte sur **73 tâches** (`adr813/jobs-inventory.json`). Il en reste 73 et non 75 : les 4 invitations de la société 101 ont été exécutées et retirées en ADR-811, et 2 tâches ont été ajoutées depuis.

| Tâches | Type | Société | Dates | Effet | Risque si traité |
|---|---|---|---|---|---|
| 26 | SendMembershipInvitationJob | 1 | 13 → 14/11 | e-mail d'invitation | membership supprimée : échec puis nouvelles tentatives |
| 19 | ProcessDocumentAiJob | non identifiable | 14 → 16/11 | analyse IA | document supprimé : échec |
| 1 | ProcessDocumentAiJob | 101 | 16/11 | analyse IA | appel au fournisseur IA (coût), écrit l'analyse |
| 14 | InvoiceCreated | non identifiable | 08 → 16/11 | e-mail de facture | facture supprimée : échec, `failed_jobs` |
| 3 + 1 | InvoiceCreated | 101, 58 | 15 → 16/11 | e-mail de facture | e-mail réel au propriétaire, sans écriture Billing |
| 2 + 3 + 1 | PlanningPublishedNotification | non identifiable (destinataire présent dans 6 sociétés), 1, 101 | 05 → 16/11 | e-mail « planning publié » | e-mail réel, périmé |
| 1 | CompanyInvitationNotification | 1 | 12/11 | e-mail d'invitation | e-mail réel |
| 2 | ScanOnboardingUploadJob | 1 | 16/11 | analyse antivirus | libère ou garde la pièce en quarantaine |

59 des 73 tâches échoueraient, car leur document, facture ou membership a été supprimé.

**Procédure d'isolement** (`docs/bmad/adr813/queue-isolation.php`) :

1. `php docs/bmad/adr813/queue-isolation.php inventory out.json` : lecture seule.
2. `php docs/bmad/adr813/queue-isolation.php isolate 101 91,92,93,99` : simulation. Le script **refuse** toute tâche dont la société n'est pas exactement 101, qui n'est pas identifiable, ou qui est réservée.
3. Ajouter `--execute` pour déplacer seulement ces lignes vers la file `recette-101`.
4. `php artisan queue:work database --queue=recette-101 --stop-when-empty --tries=1` : un worker lié à cette file ne peut consommer aucune autre tâche.

Vérifié en simulation : refus pour les tâches 37 (non identifiable) et 99999 (absente). Les 5 tâches de la société 101 sont acceptées et rien n'a été déplacé. La tâche 98 (IA, coût) est à exclure, sauf décision contraire.

## 5. Société 101

- Conservée, avec toutes ses écritures. Aucune facture, aucun abonnement ni aucune transaction n'est supprimé. Les numéros sont inchangés (INV-2026-000051, annexes 111 et 112 sans numéro).
- Elle est identifiable :
  - nom « E2E Recette 8091 433828 » ;
  - 5 comptes en `@leezr.invalid` (TLD réservé RFC 2606, jamais distribué) ;
  - registre exact de ses lignes (36 tables, 248 lignes, utilisateurs, factures) dans `adr813/fixture-company-101.json`.
- Aucun marqueur « fictif » n'existe en base (ADR master plan). Ajouter une colonne serait une nouvelle architecture, ce n'est donc pas fait.
- **Nouvelle société fictive 102** « E2E Recette 813 667092 » : créée par la régression d'inscription, avec un propriétaire en `@leezr.invalid`, une offre Starter et aucune facture.

## 6. Validation

- **Tests** :
  - `Adr813UxFinishingTest` : 10/10. Les 10 échouent sur le code d'origine (vérifié dans un worktree à `2345b37c`).
  - `Adr797JobdomainModernizationTest` : la valeur attendue est le nouveau nom par défaut.
  - Suites liées (métier, invitations, documents, Onboarding, notifications, e-mails, audit, presets) : 1058 réussis.
- **Navigateur** :
  - Inscription réelle : 201, 30 appels API, 0 réponse 429. Rôles en français en base et à l'écran (`r8-roles-fr.jpg`). Société 1 inchangée.
  - Audit : libellés en FR et EN, code visible dans le détail, desktop et mobile sombre, sans débordement ni erreur.
  - Mon espace : formats affichés. Mon activité : bloc clarifié (desktop et mobile).
  - Tableau de bord Platform : graphiques affichés, 0 erreur console.
  - E-mail « mot de passe oublié » réel : entièrement en français.
- **Suite complète** : 4636 réussis, 12 ignorés, 3 échecs historiques `ControllerSizeInvariantTest` (inchangés).
- **Build** : propre. **Migrations** : aucune en attente.
