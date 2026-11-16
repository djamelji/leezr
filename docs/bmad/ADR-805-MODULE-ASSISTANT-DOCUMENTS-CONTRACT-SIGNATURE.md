# ADR-805 — Configuration modulaire : Documents RH, Contrats, Signatures

> 2026-11-16 · DEV uniquement · base `85c08c5f` · aucun push.
> Modules : `workforce_documents`, `workforce_contract`, `workforce_signature`. Résidus ADR-804 (congés) traités au §8.

## 1. Vérifications préalables (ciblées, sans audit global)

**Ce que le serveur fait réellement**

| Module | Permission | Lue par | Périmètre réel |
|---|---|---|---|
| Documents RH | `workforce_documents.view` | 8 routes (registre, fiche, téléchargement, version signée, par collaborateur, statistiques, modèles) | soi + rapports directs ; **tous** = RH Workforce (`workforce.view` + `workforce.manage`) ; contenu salarial = `workforce.compensation_read` (`GeneratedDocumentAccess`, ADR-771) |
| Documents RH | `workforce_documents.generate` | génération (+ brouillons et bulletins officiels) | — |
| Documents RH | `workforce_documents.manage_templates`, `workforce_documents.sign` | **aucune route, aucun code** | inertes (cf. risques) |
| Contrats | `.view` / `.manage_templates` / `.generate` | 13 routes | génération : périmètre collaborateur ; aperçu : salaire masqué sans `compensation_read` |
| Signatures | `.view` / `.request` / `.manage` / `.view_evidence` / `.admin` | 24 routes | `SignatureReadModel` = soi + rapports directs ; tous = RH Workforce |
| Signatures (libre-service) | aucune (propriété) | `signature/me*` | ses propres demandes |
| Signataire / prestataire | aucune | `api/public/signature/{token}`, `api/webhooks/signatures/{provider}` | **non gardés par le module** |

**Sources de vérité, sans duplication**
- Modèles de contrat : `ContractTemplateService::available` (catalogue Platform applicable + modèles de l'entreprise), géré dans Documents → Modèles.
- Réglages de signature : `SignatureSettingsService::resolve`, gérés dans Réglages → Signature électronique (`workforce_signature.admin`).
- Pièces administratives et confidentielles : DMS (`documents.*`, module Core Documents).
- Exigences documentaires : Requirements. Non modifié et non lu par l'assistant.
- Fiche personne et intégration (Person, Candidate Onboarding) : inchangées. Les documents générés restent accessibles à l'intéressé par la route libre-service `me/generated-documents` (module Workforce).

**Ce qui est réellement exécuté** (et donc seul vérifié par l'assistant)
- Un contrat se génère depuis un modèle **publié** et actif.
- Une signature ne part qu'avec un prestataire **résolu** :
  - en PROD, il n'y a pas de prestataire par défaut et le prestataire interne est bloqué ;
  - en DEV, le prestataire interne est un outil de test.

## 2. Réutilisations (aucun moteur ajouté)

- **Moteurs inchangés** : `ModuleActivationEngine`, `ModuleSetupService`, `CapabilityAccessService`, `MembershipSecurityPolicy`, `PresetService`, DMS, moteurs de contrats et de signatures.
- **Interface** :
  - même assistant (`_ModuleSetupWizard`) et même composant d'accès ;
  - même aperçu obligatoire ;
  - même édition ultérieure dans Équipe → Rôles ;
  - suggestions de preset inchangées : aucune application automatique.
- **Autorités d'accès réutilisées** pour les deux corrections du §6 : `GeneratedDocumentAccess` et `WorkforceAccess::scopeIdsOrNull`.

## 3. Contributions des trois modules (déclaratives)

| Module | Étapes | Obligatoire | Recommandé | Empreinte des réglages effectifs | Accès réglés ailleurs (lecture seule) |
|---|---|---|---|---|---|
| Documents RH | Accès → Confirmation | accès décidés | un rôle non propriétaire peut générer | aucune (pas de réglage) | `workforce.team` (documents de tous), `workforce.hr_management` (salaire), `documents.management` (pièces confidentielles) |
| Contrats | Accès → Modèles de contrat → Confirmation | accès décidés + ≥ 1 modèle publié disponible | un modèle par défaut | modèles publiés (id, version, défaut) | `workforce.team`, `workforce.hr_management` |
| Signatures | Accès → Réglages de signature → Confirmation | accès décidés + prestataire résolu | prestataire non « test » | prestataire, niveau, format, validité, relance, ordre résolus | `workforce.team`, `workforce.hr_management` |

**Drapeaux de capacités** (fidèles au serveur)
- `workforce_documents.employee` et `workforce_signature.readonly` ont `scope: team`.
- `workforce_documents.management` a `is_admin` : il était déjà composé de permissions de gestion.

**Accès sensibles réglés ailleurs**
- Contrat `related` (nouveau) : les capacités d'**autres** modules qui décident d'une partie de l'accès.
- Elles sont affichées avec leurs détenteurs réels et un lien « Modifier dans Équipe ». Elles ne sont jamais dupliquées ni modifiables depuis l'assistant.

**Séparation des documents** : personnels (libre-service, propriété), RH (registre `workforce_documents`), administratifs et confidentiels (DMS, `documents.*`).

**Impact de désactivation** : l'interface optionnelle `ModuleDeactivationImpact` est implémentée par Documents (documents émis) et Signatures (demandes en cours). Elle est lue par `GET /modules/{key}/deactivation-impact` et ne modifie ni ne bloque jamais la désactivation.

## 4. Parcours avant / après

**Avant**
- On activait le module, puis rien n'indiquait qui pouvait faire quoi ni ce qui manquait.
- Signatures en PROD : module « actif » sans prestataire, donc aucune demande possible, sans le savoir.
- Contrats : actif sans modèle publié.
- Désactivation : seule la facturation était expliquée ; rien sur les signatures en cours ni sur les documents émis.

**Après**
1. **Activer** (devis inchangé ; Signatures et Contrats entraînent Documents RH).
2. **Accès** : capacités du module par rôle, puis les accès sensibles réglés ailleurs, en lecture seule. Aperçu obligatoire, application explicite, aucun droit à l'activation.
3. **Réglages indispensables** (Contrats et Signatures seulement) : récapitulatif sur l'API existante, avec un lien vers l'écran existant.
4. **Confirmation** : obligations, puis recommandations, puis dépendances avec leur état. Le module est prêt seulement si les contrôles serveur passent.
5. **Retour ultérieur** : Documents → Outils → « Configurer les modules » ouvre le même assistant (aucune nouvelle page) ; ou Équipe → Rôles.

## 5. Contrôles réellement opérationnels (vérifiés par tests et E2E)

- Personnel / équipe / tous : registre, fiche, téléchargement et liste des signatures appliquent le périmètre.
- Sensible : un document qui contient la paie exige `compensation_read` (403 sans, 200 avec).
- Rôle opérationnel : les 3 bundles de gestion sont refusés (`operational_role`). La délégation est la même (`beyond_your_rights`).
- Multi-tenant : un document d'une autre société renvoie 404 (fiche et téléchargement).
- Désactivation :
  - **rien n'est supprimé ni annulé** ; la demande de signature en cours garde son statut ;
  - le parcours public du signataire et les webhooks continuent (ils ne sont pas gardés par le module) ;
  - le suivi côté entreprise reprend à la réactivation ;
  - les documents émis restent accessibles à l'intéressé.
- Réactivation : mêmes demandes, mêmes réglages, mêmes droits ; « Configuration en cours » jusqu'à une nouvelle confirmation.

## 6. Défauts de sécurité trouvés et corrigés

1. **Registre RH non borné.** `GET /workforce/documents` (liste) et `GET /workforce/documents/{id}` (fiche) renvoyaient les métadonnées de **tous** les documents de l'entreprise à tout détenteur de `workforce_documents.view`, alors que le bundle annonce « ses propres documents ». Le téléchargement était déjà protégé.
   - Correction : la liste est bornée par `WorkforceAccess::scopeIdsOrNull` sur exactement les sujets que `GeneratedDocumentAccess::employeeIdOf` rattache à une personne. Les bulletins restent régis par la confidentialité de la paie. La fiche applique le même périmètre.
2. **Génération hors périmètre.** `POST /workforce/documents/generate` générait pour n'importe quel collaborateur. La réponse contenait aussi le contenu de génération brut, salaire compris pour un sujet de type contrat.
   - Correction : on ne génère que ce qu'on pourrait ouvrir (`GeneratedDocumentAccess::canOpen` sur le sujet demandé).
3. **Catalogue des modules périmé** après activation (corrigé en ADR-804) : la recette le confirme.

## 7. Risques de compatibilité

- **Les correctifs 1 et 2 restreignent volontairement** des utilisateurs qui voyaient ou généraient au-delà de leur périmètre. Un rôle « gestion documents » sans vue RH (`workforce.view` + `workforce.manage`) ne voit plus que soi et ses rapports directs.
  - À vérifier en PROD avant déploiement : rôles qui ont `workforce_documents.view` ou `generate` sans `workforce.view` + `workforce.manage`.
  - Aucun droit n'a été changé automatiquement.
- `workforce_documents.manage_templates` et `.sign` sont inertes.
  - Je ne les ai **pas** marquées `unwired` : elles sont dans le bundle de gestion, avec `generate` qui, lui, est opérant.
  - À décider : les retirer du bundle (changement de droits, hors lot) ou les brancher.
- Libellés des capacités de ces modules en anglais (« Signatures Management ») : ils n'ont pas de traduction dans `permissionCatalog.bundles`. Préexistant, non traité (hors périmètre UI de ce lot).
- Désactiver Documents RH désactive aussi Contrats et Signatures (dépendance déjà existante). C'est désormais affiché, avec l'impact.
- Les entreprises existantes restent en `legacy` : aucune migration de statut.

## 8. Résidus ADR-804

**A. Usage de `workforce_leave` sur DEV** (lecture seule, 2026-11-16)

| Société | Workforce | Congés | Raisons | Demandes (en attente) | Types | Soldes |
|---|---|---|---|---|---|---|
| 1 leezr-logistics | oui | oui | direct + required | 17 (1) | 6 | 12 |
| 5 beta-services-e2e | oui | oui | required | 0 | 0 | 0 |
| 21 c2-recette | oui | **non** | — | 0 | 0 | 0 |
| 22 sec-recette | oui | **non** | — | 0 | 0 | 0 |
| 31–34 m6e-load-* | oui | oui | direct | 1 / 3 / 5 / 10 | 6 | 0 |
| 58 vortex | oui | oui | direct + required | 0 | 0 | 0 |

**Procédure de vérification PROD** (à exécuter par un administrateur, en lecture seule ; non exécutée ici) :

```sql
-- Sociétés avec Workforce actif SANS le module Congés, et leurs données congés.
SELECT c.id, c.slug,
       (SELECT COUNT(*) FROM workforce_leave_requests r WHERE r.company_id = c.id)                       AS requests,
       (SELECT COUNT(*) FROM workforce_leave_requests r WHERE r.company_id = c.id AND r.status='pending') AS pending,
       (SELECT COUNT(*) FROM workforce_leave_types t    WHERE t.company_id = c.id)                       AS types,
       (SELECT COUNT(*) FROM workforce_leave_balances b WHERE b.company_id = c.id)                       AS balances
FROM companies c
WHERE EXISTS     (SELECT 1 FROM company_module_activation_reasons a WHERE a.company_id = c.id AND a.module_key = 'workforce')
  AND NOT EXISTS (SELECT 1 FROM company_module_activation_reasons a WHERE a.company_id = c.id AND a.module_key = 'workforce_leave')
ORDER BY requests DESC;
```

1. Exécuter la requête sur un réplica ou en transaction de lecture.
2. Si 0 ligne avec `requests > 0` ou `balances > 0`, aucun impact : déployer.
3. Sinon, appliquer la stratégie ci-dessous **avant** le déploiement.

**Stratégie de compatibilité proposée** (à valider, rien n'est appliqué)
- **Option 1, recommandée** : pour chaque société concernée, l'entreprise ou le support active le module Congés. Le module est payant, il faut donc une décision commerciale explicite (geste tarifaire si besoin). Aucune activation automatique.
- **Option 2** : déploiement en deux temps. On garde temporairement la lecture libre-service (`GET me/leaves`, `me/leave-balances`) sous le seul `workforce`, pour que les salariés conservent l'historique ; les écritures et la gestion exigent le module Congés. On retire ensuite cette tolérance après la communication.
- Dans tous les cas : aucune donnée n'est supprimée, et la réactivation retrouve tout.

**B. Permission `leave_adjust`**
- **Besoin métier** : corriger un solde (report, régularisation, erreur d'acquisition).
- **Mécanisme existant** :
  - registre des congés (`LeaveLedger`, écritures `adjustment` / `grant` / `storno`, audit `warning`) ;
  - use cases `GrantLeaveUseCase` et `StornoLedgerEntryUseCase` ;
  - **aucune route HTTP ni aucun écran**.
- **Décision** :
  - la brancher demanderait un endpoint et un tiroir dans la vue des soldes, soit une **nouvelle fonction** (extension, gelée par ADR-561) ;
  - la permission reste **inerte et explicite** (`unwired`, visible seulement en mode avancé avec un avertissement) ;
  - câblage prêt quand il sera décidé : `POST employees/{id}/leave-adjustments` → `GrantLeaveUseCase` (crédit) ou `LeaveLedger::debit` de type `adjustment`, avec un garde `workforce.leave_adjust`. Aucun moteur de solde à créer.

**C. Recette** : les cas nominaux sont prouvés par des réponses 200/201 **et** l'état en base (voir §9). Plus aucun 404 ni 422 n'est compté comme une réussite.

## 9. Résultats E2E (société fictive isolée `e2e-8031-*`, utilisateurs `@leezr.invalid`)

**Configuration**
- **Signatures** :
  - activation (Documents RH entraîné), aucun droit ;
  - la gestion est refusée au rôle opérationnel ;
  - l'étape réglages signale honnêtement le prestataire de test ;
  - Prêt.
- **Documents RH** : 2 étapes, Prêt.
- **Contrats** : modèle du catalogue publié affiché, recommandation « modèle par défaut », Prêt.
- **Congés** : Prêt.
- **Espace Documents** : Outils → « Configurer les modules », 3 entrées vers l'assistant.

**Usage**

| Acteur | Action | Résultat | Vérification |
|---|---|---|---|
| Owner | génère un document (salarié sans compte) | 201 | — |
| Chef | génère un document (son rapport) | 201 | — |
| Chef | génère un document (hors périmètre) | 403 | — |
| Chef | crée une demande de signature | 201 | ligne en base, prestataire `internal`, statut en cours |
| Chef | lit les réglages de signature | 200 | — |
| Agent | registre | 200 | contient son document, pas celui d'autrui |
| Agent | fiche d'un autre document | 403 | — |
| Agent | téléchargement du sien | 200 | — |
| Agent | liste des signatures | 200 | — |
| Agent | réglages de signature | 403 | — |
| Agent | création de signature | 403 | — |
| Agent | demande de congé | 201 | statut `pending` en base |
| Chef | validation du congé | 200 | statut `approved` en base |

**Désactivation**
- La boîte affiche l'impact (2 documents émis, 1 signature en cours) ; annulation possible.
- Désactiver Signatures : demandes inchangées (statuts identiques), droits conservés.
- Réactivation : « Configuration en cours ».
- Mobile en mode sombre sans défilement horizontal.

**Société 1 inchangée** : droits, configurations, raisons, documents, signatures, modèles, congés et factures identiques avant et après.

**Écritures de facturation de la fixture** (activation réelle des add-ons payants, puis nettoyage contrôlé)

| Élément | Détail |
|---|---|
| Abonnements | `workforce_documents` 9,00 € HT ; `workforce_leave` 19,00 € HT (Contrats et Signatures : 0 €) |
| Factures « open » | INV-2026-000049 (10,80 € TTC), INV-2026-000050 (22,80 € TTC) |
| Autres lignes | 6 écritures de grand livre, 1 portefeuille |
| Paiements / avoirs | 0 / 0 (vérifié avant suppression) |

- Ces lignes ont été supprimées par identifiants exacts de la société fictive.
- Les fichiers PDF générés de la fixture ont été supprimés, ainsi que les dossiers vides des fixtures.
- Aucune donnée de facturation réelle n'a été touchée.

Les fixtures des exécutions intermédiaires (sociétés 75 et 76) ont été nettoyées de la même façon ; la recette finale a utilisé la société 79. Les identifiants 77 et 78 ont été consommés par deux créations annulées (transaction) : aucune ligne n'a subsisté.

Captures : `docs/bmad/adr805/`.

## 10. Tests

- `Adr805DocumentsContractSignatureSetupTest` : 10 tests.
  - étapes ;
  - périmètres et accès liés ;
  - délégation et rôle opérationnel ;
  - personnel, positif et négatif ;
  - gestion (201 + base) ;
  - sensible et autre tenant ;
  - génération bornée ;
  - contrat prêt et empreinte ;
  - signature (prestataire, empreinte) ;
  - désactivation et réactivation.
- Tests ADR-803 et ADR-804 adaptés : l'exemple « sans contrat » devient `workforce_payroll`.
- Régression ciblée documents, signatures, contrats, DMS et paie : 781 tests.
- **Suite complète** : 4580 réussis, 12 ignorés, 3 échecs, tous dans `ControllerSizeInvariantTest`.
  - Le premier passage avait révélé un **nouveau** cas fautif : `WorkforceDocumentController` à 251 lignes, causé par ce lot.
  - Corrigé en déplaçant la règle de périmètre dans `GeneratedDocumentAccess::inScope` : le contrôleur est revenu à 248 lignes.
  - La liste des cas fautifs est maintenant identique à la référence ADR-804 (24 entrées).
  - Régression ciblée relancée après cette correction : 781 tests verts.
- Build `pnpm build` propre.
