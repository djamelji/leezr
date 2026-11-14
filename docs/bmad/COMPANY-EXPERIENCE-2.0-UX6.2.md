# Company Experience 2.0 — UX6.2 : corrections finales produit (ADR-783)

> Base `a0edf3c7` · DEV · aucune migration, aucun moteur documentaire nouveau, Billing et paie intacts.
> Captures : `docs/bmad/ux6-782/after62-*`.

## 1. Identité employeur des contrats

**Cause** : le résolveur de variables documentaires (`company.address`, `company.city`, `company.siret`…) et l'écran « Contrats de travail » lisaient les colonnes `companies.address_*` / `siret`, qu'aucun formulaire n'alimente ; le profil Company enregistre ces informations en champs dynamiques (source déjà utilisée par la facturation). Un contrat pouvait sortir sans adresse ni ville.

**Correction** :
- `CompanyLegalIdentity` (read-model, lecture seule) : source canonique = profil Company ; les colonnes historiques ne servent qu'en repli quand le champ du profil est vide ; rien n'est inventé (valeur absente = vide).
- `DocumentVariableResolver::resolveCompany` et l'identité employeur de `/workforce/settings` lisent ce read-model (mêmes clés de variables, aucune variable retirée).
- Génération de contrat (`POST /workforce/contract/generate`) : si adresse, code postal ou ville manquent, refus 422 `employer_identity_incomplete` avec la liste et le lien vers le profil ; rien n'est émis. Le dialogue l'affiche en ligne avec « Compléter le profil de l'entreprise » ; l'écran « Contrats de travail » prévient en amont.
- Documents déjà générés ou signés : jamais modifiés (instantané et PDF figés, testé).
- DSN : non modifiée (déclaratif de paie, hors périmètre) — elle lit encore les colonnes.

**Tests** (`Ux62EmployerIdentityTest`, 6) : profil renseigné → variables et PDF réel ; profil incomplet → 422 actionnable, aucun document ; données historiques en repli et rien d'inventé ; aucune fuite entre entreprises ; contrat existant inchangé après modification du profil ; écran employeur lisant la même source. Deux tests existants (`Adr771GeneratedContractFilingTest`, `WorkforceContractTest`) reçoivent une adresse historique pour leurs entreprises de test (nouvelle règle) : 37/37.

## 2. Catalogue Modules

- Noms et descriptions traduits par clé (`moduleCatalog.*`, repli sur le catalogue Platform) ; « Featured » → « Recommandé ».
- Module en préparation : état « Bientôt disponible » (non cliquable) au lieu d'un bouton désactivé ; classé après les offres activables.
- Résumé : activés, inclus, souscrits, à découvrir ; libellés insécables sur mobile.
- Prix, règles commerciales, activation, souscription : inchangés.

## 3. Dialogues documentaires

`AppDialog` (socle) : titre et phrase, bouton fermer étiqueté dans l'en-tête, corps défilant, pied d'actions stable à droite, plein écran sur smartphone. Appliqué à : dépôt, demande (ordre « quoi » puis « à qui »), génération de contrat, préparation de signature (assistant). Les règles et permissions sont inchangées.

## 4. Finitions

- Mon activité : « Mon contrat » en grille libellé/valeur (« 35 h » au lieu de « 35.00h »).
- Accueil : rangées compactes (UX6.1) ; graphiques non retravaillés.

## 5. Résultats

| Niveau | Résultat |
|---|---|
| HTTP ciblés (identité, contrats) | 37/37 |
| Suite complète (une fois, après stabilisation) | 4308 réussis, 12 ignorés, 4 échecs : **3 historiques** `ControllerSizeInvariantTest` (taille, `DB` facade, `save()` en contrôleur — mêmes tests qu'avant UX4) + `WorkforceContractTest::generate` (fixture sans adresse employeur, conséquence attendue de la règle ; fixture corrigée et vert à la relance ciblée) |
| Navigateur | dialogues (desktop, mobile), Modules, tiroirs, UX2 et UX3 (profils, sans Workforce, mobile) : 19/19 (profil paie UX3 rejoué isolément après un refus 429 sur `/api/login`) |
| Build | propre |

## 6. Limites de recette

- Génération réelle d'un contrat en DEV non exécutée : elle crée un document immuable. Couverture par tests HTTP sur base isolée (PDF réel en stockage factice, instantané vérifié).
- Signature de bout en bout non rejouée (même raison).
- Accueil : graphiques non revus.

## 7. Défauts bloquants restants

Aucun identifié. À arbitrer : la DSN lit encore les colonnes historiques de l'entreprise (même cause, périmètre paie).
