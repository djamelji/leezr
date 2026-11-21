# Leezr 4.2 — M5 : ergonomie Platform (ADR-842)

Recette navigateur (`tests/e2e/audit842-m5-recipe.spec.js`, 5/5) sur le clone marqué `leezr_audit842` avec 5 profils : super-administrateur, Finance (`finance-reader`), Gouvernance (`gov-mgr`), Support (`support-only`), création seule (`creator842`). Recette M1 rejouée (4/4) : le changement d'offre avec aperçu et confirmation fonctionne depuis son nouvel emplacement. Aucune route, permission, règle Billing ou manifeste de module modifié.

## Structure avant / après

### Facturation (UXP-05)

| Avant (15 onglets au même niveau, défilement horizontal) | Après (5 groupes métier → pages du groupe) |
|---|---|
| Tableau de bord | **Vue d'ensemble** — indicateurs, alertes, activité |
| Abonnements, Abonnements à vérifier (revue tarifaire) | **Abonnements** — contrats, renouvellements, tarifs à vérifier |
| Factures, Paiements, Prélèvements programmés, Relances, Avoirs, Portefeuilles | **Factures et paiements** — facturation, encaissements, relances, remboursements |
| Coupons | **Offres commerciales** — promotions ; lien « Gérer les plans dans le Catalogue » (les plans restent au Catalogue, source unique) |
| Anomalies & rapprochement, Récupération, Forensique, Gouvernance, Grand livre | **Contrôle et suivi** — diagnostic et comptabilité (lecture) |

- Adresses **inchangées** : `/platform/billing/{page}` ouvre la même page, dans son groupe (vérifié pour les 15 ; retour arrière vérifié).
- Permissions **inchangées et par page** (ADR-839) : un groupe n'apparaît que si l'une de ses pages est permise ; Finance ne voit pas « Offres commerciales » ; un groupe d'une seule page n'affiche pas de seconde rangée.
- Chaque groupe a une phrase d'explication. Une seule entrée de menu (manifeste `platform.billing`) — pas de menu codé en dur.
- Captures : avant `captures/p-superadmin_platform_billing_overview.jpg` → après `m5/apres-A1-facturation-groupes.jpg`, `apres-A2-factures-et-paiements.jpg`, `apres-A3-facturation-mobile.jpg` (390 px, sans défilement horizontal de la page).

### Fiche entreprise (UXP-06, R3)

| Avant | Après | Raison |
|---|---|---|
| Synthèse : carte « L'entreprise » (métier, abonnement, propriétaire, marché, date) + même contenu dans le panneau gauche + tuile « Abonnement » | Synthèse : statut, membres, modules actifs ; identité **dans le panneau gauche seulement** | une information = un emplacement |
| Changement d'offre = un sélecteur dans le formulaire d'identité (« Configuration ») | Carte « Changer d'offre » dans **Abonnement et facturation**, à côté de l'abonnement, réservée à `manage_companies`, aperçu chiffré puis confirmation (inchangés) | consultation / configuration / opération commerciale séparées |
| Onglets « Aperçu », « Configuration », « Facturation » | « Synthèse », « Identité et adresses », « Abonnement et facturation » | intitulés qui disent ce qu'on y trouve |

Création assistée (ADR-834) et champs configurables (ADR-835) non touchés. Captures : avant `captures/p-superadmin-w2_platform_companies_1.jpg` → après `m5/apres-B2-fiche-synthese.jpg`, `apres-B3-apercu-changement-offre.jpg`.

### Autres pages

| Constat | Correction |
|---|---|
| UXP-10 Liste des entreprises : « 9 hours ago », filtres tronqués, « Trial expire » | dates relatives dans la langue de l'interface ; filtres « Statut / Abonnement / Santé » ; « Essai bientôt terminé » |
| UXP-11 boutons-icônes sans nom (listes Entreprises, Utilisateurs Platform) | nom accessible + infobulle ; menu « ⋮ » qui répétait les mêmes actions supprimé ; Suspendre / Réactiver / Supprimer affichés seulement avec le droit correspondant (R1) |
| UXP-12 personnalisateur de thème Vuexy visible par tous | réservé à `manage_theme_settings` |
| UXP-09 journal des e-mails : sujets en clés brutes | clé résolue vers son modèle à l'affichage (« Facture #… — … … ») ; données inchangées |
| UXP-14 fiche module : onglets vides | Fonctionnalités / Dépendances / Permissions affichés seulement s'ils ont un contenu (l'adresse directe reste valable) |

## Profils (recette)

- Super-administrateur : Facturation par le menu, groupes, pages, adresses directes, clavier (Entrée sur un groupe), mobile ; fiche entreprise ; aperçu de changement d'offre (annulé) ; journal des e-mails ; fiche module.
- Finance : groupes réduits à ses droits ; aucun personnalisateur de thème.
- Gouvernance (Catalogue), Support (Communications › Support), Création (Nouvelle société) : page d'arrivée sans refus ; Facturation → page « Accès réservé ».
- Aucune erreur JS, aucune directive non résolue, aucun refus d'API inattendu.

## Limitations restantes

- UXP-13 (Modèles de contrat et Marchés dans le Catalogue) : touche les manifestes de navigation — non fait, à décider.
- UXP-11 : corrigé sur les deux listes citées par l'audit ; `IconBtn` étant un composant `@core` (non modifiable), les autres pages restent à traiter une par une.
- UXP-15 (contraste, champs sans libellé) non traité ; la fiche entreprise garde 7 onglets (défilement par flèches sur écran moyen).
- UXP-02 (fiche d'un module non synchronisé) non traité.
