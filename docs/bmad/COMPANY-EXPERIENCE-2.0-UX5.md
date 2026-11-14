# Company Experience 2.0 — UX5 : Réglages (ADR-781)

> Base `33bc63c5` · DEV uniquement · aucune migration, aucun nouveau moteur, aucune permission ajoutée ou élargie ; Billing, paie et calcul des congés intacts (le correctif de notification ne touche ni les flux de paiement ni le grand livre).
> Preuves : `docs/bmad/ux5-781/` · recette `tests/e2e/ux5-settings.spec.js` · sonde `tests/e2e/ux5-probe-before.spec.js` (`UX5_PHASE=before|after`).

## 0. Correctifs préalables

### 0.1 Confidentialité — statistiques d'absences

`GET /workforce/leaves/statistics` renvoyait les totaux de toute l'entreprise à tout détenteur de `leave_request`. Il suit désormais la politique existante : vue globale (`leave_view_all`, `leave_approve`, propriétaire) → entreprise ; sinon → périmètre réel (`WorkforceAccess::visibleEmployeeIds` : soi + rapports directs) ; aucune fiche → rien. Le read-model accepte ce périmètre ; aucun calcul modifié.
Tests (`Ux41LeaveBalanceScopeTest`, +4) : RH vue globale et propriétaire = entreprise ; manager = soi + rapport ; salarié = lui seul (aucun indicateur sur autrui) ; compte sans fiche = vide ; sans `leave_request` = 403 ; autre entreprise jamais comptée.

### 0.2 Notifications de paiement (vendeur / client)

**Cause** : le sujet `billing.payment_received`, de portée **entreprise**, est envoyé au propriétaire de l'entreprise **cliente** quand sa facture d'abonnement est payée (`InvoiceIssuer`, `InvoiceAutoChargeService`). Son texte était rédigé du point de vue du vendeur : « Paiement reçu — Un paiement de X a été reçu ». Côté Platform, aucun avis d'encaissement n'était envoyé (les sujets Platform de facturation existaient dans le registre mais n'étaient jamais émis).

**Défaut associé découvert** : aucun e-mail de facturation n'était traduit côté serveur (aucune source pour le groupe `email`) ; ils partaient avec leurs clés comme objet (journal DEV : « email.billing.invoice_created.subject »).

**Correction (à la source, sans toucher aux flux)** :
- Client : même sujet (clé conservée pour les préférences déjà enregistrées), libellé « Payment Confirmed », textes « Paiement effectué — Votre facture de X est acquittée » ; e-mail « Paiement confirmé — facture acquittée ».
- Vendeur : nouveau sujet Platform `platform.payment_collected` (« Paiement encaissé — Société a réglé X (facture N) »), émis une seule fois quand un paiement passe à `succeeded` (même condition que l'écriture comptable), aux utilisateurs Platform ayant la visibilité facturation (`view_billing`, filtre existant du répartiteur). Observateur côté Notifications (`PlatformPaymentCollectedNotifier`), non bloquant, idempotent par paiement. Aucun fichier Billing modifié.
- E-mails : `lang/{fr,en}/email.php` lit le bloc `email` des fichiers de langue de l'interface (source unique, `{x}` → `:x`). Tous les e-mails de facturation sont désormais traduits.
- Tests (`Ux5PaymentNotificationAudienceTest`, 5) : propriétaire client = « Paiement effectué », jamais « reçu », rattaché à son entreprise ; e-mail traduit sans clé brute ; utilisateurs Platform facturation = « Paiement encaissé », support sans `view_billing` exclu, jamais le client ; un seul avis par paiement, rien pour un échec ; aucun propriétaire d'une autre entreprise notifié. Deux anciens tests (`BillingLotCTest`) qui exigeaient la clé brute dans l'e-mail vérifient désormais le texte traduit.
- Sujets synchronisés en DEV (`NotificationTopicSeeder`, mise à jour non destructive). Les notifications déjà émises gardent leur ancien titre.

## 1. Diagnostic (avant, `before-owner.json`)

| Réglage | Fonction | Fréquence | Profil | Constat |
|---|---|---|---|---|
| Entreprise (`settings.view`) | identité, coordonnées, adresses, informations légales | mise en route, rare | dirigeant | **20 champs** sur un écran ; adresse de facturation toujours redemandée (interrupteur « identique » remis à non à chaque ouverture) ; informations légales sans titre |
| Organisation (`workforce.view`) | services, postes, lieux | mise en route, puis rare | RH | lieu : fuseau horaire en texte libre (« Europe/Paris ») |
| Paramètres RH | semaine, règles de temps, contrats, signature | rare | RH | 4 onglets |
| Rôles (dans Équipe) | rôles et permissions | rare | dirigeant | absent de « Réglages » |
| Types d'absence (dans Absences) | types et droits | rare | RH | absent de « Réglages » |
| Types de documents, modèles (Documents › Outils) | pièces, obligations, modèles | rare | RH | absents de « Réglages » |
| Signature (onglet des paramètres RH) | prestataire | mise en route | dirigeant | état du prestataire invisible sans ouvrir l'onglet |
| Abonnement & factures | formule, factures | mensuelle | dirigeant | — |
| Automatisations, Modules, Journal d'audit | avancé | rare | dirigeant | au même niveau que le reste |

Menu : « Réglages » = 7 sous-entrées pour le propriétaire, rien sur les réglages rangés ailleurs.

## 2. Architecture après

| | Avant | Après |
|---|---|---|
| Menu | Sous-menu de 7 entrées | **Une entrée « Réglages »** ouvrant `/company/settings` |
| Source des entrées | Manifestes (`group: settings`) | Inchangée : manifestes, filtrés par NavBuilder (module, plan, surface, permissions). Chaque entrée porte `settings: {section, level, status?}` |
| Réglages rangés ailleurs | Introuvables depuis Réglages | Contribués par **leur** module au groupe `settings` (rôles, types d'absence, types et modèles de documents, signature), avec les mêmes gardes que la page cible (nouvelle contrainte générique `allPermissions` quand la page en exige plusieurs) |
| Hub | — | Rubriques (Entreprise, Organisation, Accès & sécurité, Travail & RH, Documents, Abonnement & factures, Automatisations & intégrations) ; recherche ; badge « Essentiel » ; états « à compléter » ; **Administration avancée** repliée (Modules, Journal d'audit) |
| Retour | — | `SettingsReturnLink` : « ← Réglages » quand on vient du hub, « ← Retour » vers l'opération d'origine (`?back=`, chemin interne uniquement) |
| Liens contextuels | — | `useSettingsEntry(key)` : lien seulement si l'entrée est autorisée ; « Préférences » dans l'en-tête Planning & temps |

Le frontend ne contient aucun catalogue de modules : il range ce que le serveur lui donne. API `/nav` compatible : l'entrée hub garde ses `children` et porte `hub: true`.

## 3. Pages conservées et regroupées

Aucune page supprimée ni fusionnée ; aucune route modifiée. Les anciens liens (`/company/profile/overview`, `/company/workforce/settings/general`, `/company/roles`, `/company/documents/settings`…) restent valides.

## 4. Essentiel / courant / avancé

| Niveau | Entrées |
|---|---|
| Essentiel (mise en route) | Entreprise, Organisation |
| Courant | Paramètres RH, Rôles & permissions, Types d'absence, Types de documents, Modèles de documents, Abonnement & factures, Automatisations, Signature électronique |
| Avancé (replié) | Modules, Journal d'audit |

## 5. Valeurs par défaut réutilisées

- Fuseau d'un nouveau lieu = fuseau de l'entreprise (marché, `world` store), rangé sous « Plus d'options ».
- Adresse de facturation = adresse de l'entreprise tant qu'elle n'en diffère pas (déduit des données).
- Premier jour de semaine, règles de pause, champs obligatoires : inchangés (marché, JobDomain, Company, conventions).
- Les champs obligatoires du JobDomain alimentent l'état « N information(s) obligatoire(s) à compléter » du hub, sans assistant ni saisie forcée.

## 6. Doublons supprimés

- Adresse de facturation redemandée quand elle est identique (5 champs et un pays en double masqués).
- Sous-menu « Réglages » qui répétait les rubriques. Aucune copie de formulaire : chaque entrée ouvre l'écran existant (une seule source de vérité par paramètre).

## 7. Parcours contextualisés

Planning & temps → « Préférences » → paramètres RH → « ← Retour » au planning (2 clics, mesuré). Documents › Outils, Équipe › Rôles, Absences › Types restent en place et sont désormais aussi atteints depuis le hub.

## 8. Autorisations (mesurées, `result-profiles.json`)

| Profil | Entrées du hub | Pages sensibles hors droits |
|---|---|---|
| Propriétaire | 12 (toutes) | — |
| Dispatcher (administratif) | Entreprise, Types de documents | Abonnement, Rôles, Modules → 403 |
| RH sans paie | Organisation, Paramètres RH, Types d'absence | Abonnement, Rôles → 403 ; Modules → accueil |
| Manager | Organisation, Paramètres RH | 403 |
| Consultation | Organisation | refusées |
| Driver, Paie, Salarié | aucune (pas d'entrée « Réglages ») | refusées |

Chaque entrée proposée s'ouvre réellement (assertion) ; 0 erreur JS, 0 réponse 403 ou 5xx. Changement d'entreprise (sans Workforce) : 12 → 7 entrées, aucune entrée Workforce. Écritures confinées à l'entreprise de recette « Beta Services ».

## 9. Mesures

Après = mesuré (clics, champs, pages, défilement horizontal). Avant = navigation à `33bc63c5` (reconstituée, non chronométrée).

| Parcours | Avant | Après (mesuré) |
|---|---|---|
| Ouvrir les réglages | 1 clic pour ouvrir un sous-menu de 7 entrées | 1 clic, 1 page, 7 rubriques |
| 1. Modifier les coordonnées | Réglages › Entreprise ; 20 champs visibles (démo) | 3 clics, 1 champ, 2 pages ; 14 champs visibles (démo) |
| 2. Configurer un site | Réglages › Organisation › Lieux ; 6 champs dont fuseau texte | 5 clics, 1 champ (nom), fuseau prérempli |
| 3. Trouver un rôle | Équipe › onglet Rôles (hors Réglages) | 1 clic depuis le hub |
| 4. Modifier une préférence | Réglages › Paramètres RH | 5 clics, 1 champ |
| 5. Type de document | Documents › Outils › Types & réglages | 1 clic depuis le hub (ouverture ; pas d'écriture sur l'entreprise de démo) |
| 6. Paramètre d'absence | Planning & temps › Absences › Types (3 niveaux) | recherche « absence » + 1 clic |
| 7. Abonnement & factures | Réglages › Abonnement | 1 clic depuis le hub |
| 8. Intégration à compléter | ouvrir Paramètres RH › Signature | visible sur le hub sans clic (« Prestataire de test : à configurer avant la production ») |
| 9. Revenir à l'opération | navigation manuelle | 2 clics (Préférences, Retour) |
| 10. Mobile | — | recherche + 1 clic ; aucun défilement horizontal (390 px, 820 px) |
| Erreurs HTTP/JS | — | 0 |

## 10. Tests

| Niveau | Contenu | Résultat |
|---|---|---|
| HTTP | `Ux41LeaveBalanceScopeTest` 12 ; `Ux5PaymentNotificationAudienceTest` 5 ; `Ux0IntentNavigationTest` (règle du hub) 9 ; tests de navigation 163 (+1 ignoré) ; facturation et notifications 906 | verts |
| Navigateur | `ux5-settings.spec.js` (5) : propriétaire (lecture), écritures Beta Services, mobile/tablette/sombre, 8 profils, changement d'entreprise | 5/5 |
| Suite complète (une fois) | `php artisan test` | 4303 réussis, 12 ignorés, **3 échecs historiques** (`ControllerSizeInvariantTest`, mêmes tests qu'avant) |
| Build final | `pnpm build` | propre |
| Régression navigateur | UX0 (9), M6.A (4), UX4 sauf le parcours de Bob (5) | 18/18 après renommage du sélecteur « Préférences » (`space-preferences`), que le test UX4 prenait pour une vue de l'espace |

## 11. Captures

`docs/bmad/ux5-781/` : `before-*` / `after-*` (desktop et mobile, chaque réglage), `j-desktop-01-hub` à `05-profile`, `j-mobile-hub`, `j-tablet-hub`, `j-mobile-billing`, `j-dark-hub`, `hub-<profil>`, `hub-no-workforce`.

## 12. Défauts résiduels

1. Écrans de réglage eux-mêmes encore denses : Types de documents (43 champs visibles), Types d'absence (onglet dans la page Absences), Paramètres RH (4 onglets). UX5 les rend trouvables ; il ne les a pas refondus.
2. Les deux états « à compléter » (profil, prestataire de signature) sont calculés par le hub à partir d'endpoints existants : une source d'état contribuée par chaque module côté serveur serait plus extensible.
3. Le lien « Préférences » n'est pas proposé sur smartphone dans Planning & temps (hub accessible).
4. L'entrée « Réglages » du menu n'est pas surlignée quand on est sur une page de réglage.
5. Le lien de retour disparaît si l'on change d'onglet dans la page de réglage.
6. Redirections hétérogènes pour une page refusée (`/company/403`, accueil, tableau de bord) — préexistant.
7. Les notifications « Paiement reçu » déjà émises en DEV gardent leur ancien titre.
8. Aucune page de connecteurs : la signature est le seul prestataire externe exposé.
9. `LeaveController` (312 lignes) et `EmployeeSelfController` (287) restent au-dessus du seuil d'architecture.

## 13. Conditions pour UX6

- Valider les rubriques et libellés du hub (« Réglages », « Administration avancée », « Essentiel », « Paramètres RH », « Types de documents »…).
- Valider les textes de paiement côté client (« Paiement effectué », « facture acquittée ») et côté Platform (« Paiement encaissé »).
- Décider si la refonte des écrans denses (types de documents, paramètres RH) entre dans UX6 ou dans un lot dédié.
