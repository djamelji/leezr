# ADR-815.2 — 09. Données, sécurité et compatibilité

> Reprend et complète `adr815/data-compatibility-matrix.md` et `adr815/company-routes-permissions.md`. Lecture seule de la base DEV ; preuves HTTP obtenues sur clone isolé.

## 1. Sécurité : actions urgentes (indépendantes de la refonte)

| ID | Gravité | Constat prouvé | Action urgente proposée | Risque si différé |
|---|---|---|---|---|
| **S1** (R0, ADR-815 C2) | **P0** | Les widgets `workforce.clock_live`, `anomalies`, `hours_trend` (garde `timesheet_view`, « own ») et `leave_pending` (garde `leave_request`) servent des **agrégats de toute l'entreprise** à un salarié. Réponse 200 : `{"not_clocked":1}`, `{"pending":1}`. | Exiger une permission de lecture collective existante : `workforce.view` + `workforce.manage`, ou `time_manage`, `timesheet_approve`, `leave_view_all`, `leave_approve`. Aucune donnée ni permission ajoutée. | Divulgation d'informations collectives (sans nom) |
| **S2** (R2) | P1 | `GET /modules/{key}/settings`, `/modules/quote` et `/modules/{key}/deactivation-preview` ne demandent aucune permission ; le chauffeur lit réglages, devis et « profils incomplets : 22 ». | Garde `modules.manage` sur les 3 routes. | Fuite d'informations commerciales et de configuration |
| **S3** (R1) | P1 (décision D3) | Le chauffeur lit l'annuaire complet : e-mail de connexion, état du compte, rôle, indicateur administrateur. Il le doit à `members.view`, attribué par défaut au rôle `driver`. | Projection « annuaire » réduite pour `members.view` sans `members.manage` **ou** retrait de `members.view` du rôle par défaut (décision). | Exposition des identifiants de connexion |
| **S4** (R4) | P2 | `theme.manage` attribué au rôle par défaut `driver`. | Retirer du rôle **par défaut** des nouvelles sociétés (les rôles existants ne sont pas modifiés sans décision). | Modification du thème par un chauffeur |

Ces quatre actions sont **petites, réversibles et sans migration de données**. Elles se testent par une matrice profil × endpoint.

## 2. Risques RBAC de la refonte

| Changement de la refonte | Risque | Garde-fou |
|---|---|---|
| Rôle d'accès = droits uniquement | Des rôles personnalisés comptent sur l'archétype pour l'arrivée et les widgets | Arrivée unique `/home` ; widgets par permission. Test « pas de nouveau 403 » (invariant M7). |
| Périmètre explicite (soi / équipe / entreprise) | Mal interpréter `workforce.view` sans `manage` | Calculé à partir de `WorkforceAccess`, sans changer la règle, puis affiché ; tout changement de règle = décision séparée |
| Déplacement de Rôles vers Réglages | Lien profond `/company/people?tab=roles` | Alias conservé |
| Exigences par poste | Un poste ne doit donner aucun droit | Aucune lecture de permission dérivée du poste (test) |
| Embaucher : rôle d'accès choisi à la finalisation | Escalade lors de la délégation | `MembershipSecurityPolicy::assertCanAssignRole` réutilisé |

## 3. Risques multi-tenant

- Tous les nouveaux écrans réutilisent des endpoints existants sous `SetCompanyContext` et le `CompanyScope` global.
- **Point d'attention 1** : la résolution des widgets reçoit désormais l'utilisateur et le salarié. Le salarié doit être résolu **dans la société courante** (`Employee::withoutCompanyScope()` interdit sans `company_id`).
- **Point d'attention 2** : l'assistant Embaucher en mode « sur place » crée un dossier sans lien envoyé. Le jeton reste nul, jamais un jeton partagé.
- **Tests** : deux sociétés, mêmes noms de postes ; aucune fuite inter-sociétés dans les listes, la conformité ou les widgets.

## 4. Compatibilité des données (synthèse)

| Donnée | Traitement | Migration ? |
|---|---|---|
| Personnes, comptes, contrats, demandes, pièces, dispositions | **Conservées telles quelles** | Non |
| `company_roles.doc_config`, `field_config`, `required_tags`, `archetype` | Lus pour **proposer** des exigences de poste, puis figés en lecture seule ; jamais supprimés avant la parité | Non (gel logique) |
| Champs personnalisés « Intitulé de poste », « Type de contrat », « Statut », « Date d'embauche » (infos complémentaires de la fiche) | Dépubliés de la fiche ; valeurs conservées et visibles dans l'historique ; proposition de reprise vers le poste, le contrat ou le statut **validée par la société** | Non (aucune écriture automatique) |
| Postes (`workforce_job_roles`) | Complétés par adoption du preset (action de la société) | Non |
| Affectation des postes aux salariés existants | Suggestion (rôle → poste), validée | Non |
| `requirements` par poste | Créés par la configuration de la société | Non (table existante) |
| Noms de modules (« Requis par ») | Un seul nom par module (données de catalogue) | Non : correction de libellés |
| Presets de test `e2e_preset_*` | Retrait par identifiants exacts sur décision | Non |

**Aucune migration de schéma n'est requise par le plan.** Les seules écritures de données sont des actions explicites des sociétés.

## 5. Rollback

- **Lots d'interface** : derrière la route existante. L'ancienne page reste accessible par alias jusqu'à validation.
- **Bascule d'autorité des exigences** (rôle → poste) : drapeau de configuration ; repli immédiat sur `DocumentResolverService` (rôle), qui reste en place.
- **Sécurité S1 à S4** : réversibles par simple retour de la garde. Les tests d'invariant empêchent toute régression silencieuse.
