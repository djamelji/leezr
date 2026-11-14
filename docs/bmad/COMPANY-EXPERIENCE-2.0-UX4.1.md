# Company Experience 2.0 — UX4.1 : stabilisation ciblée après ADR-779 (ADR-780)

> Base `8c933f07` · DEV uniquement · aucune migration, aucun nouveau moteur, aucune permission ajoutée ou élargie, aucune règle de calcul modifiée.

## 1. Faille de périmètre corrigée

| Endpoint | Avant | Après |
|---|---|---|
| `GET /workforce/employees/{id}/leave-balances` | Route `leave_request` + collaborateur de la même entreprise : **tout détenteur de `leave_request` lisait le solde de n'importe quel collègue** | + `WorkforceAccess::canViewEmployeeData(…, 'leave')` : vue globale (`leave_view_all` / `leave_approve`, propriétaire), soi-même, ou rapport direct |
| `POST /workforce/leaves` (dépôt pour un collaborateur, formulaire UX4) | Même défaut, en écriture | Même politique |

C'est la politique déjà utilisée par la liste, le détail, le calendrier, le calcul de durée, l'approbation et le refus. Aucun système parallèle. Un collaborateur d'une autre entreprise n'est pas trouvé (404, aucune donnée dans la réponse).

Audit des autres lectures de soldes et d'absences utilisées par UX4 :

| Endpoint | Verdict |
|---|---|
| `GET /workforce/me`, `GET /workforce/me/leave-balances`, `POST /workforce/me/leaves/deduction-preview` | Fiche de la personne connectée uniquement ; un `employee_id` passé en paramètre est ignoré (testé) |
| `GET /workforce/leaves`, `/leaves/{id}`, `/leaves/calendar`, `/leaves/{id}/impacted-shifts`, `/leaves/deduction-preview`, approbation, refus | Déjà soumis à la politique |
| `POST /leaves/{id}/cancel` | Route `leave_approve`, qui vaut vue globale dans la politique : périmètre de fait identique |
| `GET /workforce/leaves/statistics` | **Réserve** : agrégats de toute l'entreprise (nombres et totaux par statut, sans nom ni solde individuel) pour tout détenteur de `leave_request`. Non modifié : le read-model ne sait pas filtrer par périmètre |

## 2. Tests de sécurité (`Ux41LeaveBalanceScopeTest`, 8/8)

Propriétaire autorisé · RH avec vue globale autorisé · rôle sans `leave_request` refusé par la route · manager autorisé pour son rapport direct et refusé hors périmètre · salarié autorisé sur lui-même et refusé sur autrui (aucune donnée dans la réponse) · collaborateur d'une autre entreprise introuvable sans fuite · endpoint personnel limité à soi malgré un paramètre · dépôt d'une demande soumis au même périmètre (aucune demande créée hors périmètre).

**Contre-épreuve** : sur le contrôleur d'avant correction, 3 de ces tests échouent (manager hors périmètre, salarié sur autrui, dépôt hors périmètre). Ils passent après correction.

## 3. Refactoring du contrôleur

`EmployeeSelfController` : **366 → 287 lignes** (322 avant UX4). Réponses publiques inchangées (mêmes clés, mêmes codes).

| Responsabilité | Déplacée vers (existant) |
|---|---|
| Pointage du jour et pointage resté ouvert | `TimeEntryReadModel::selfClockState()` |
| Recherche du pointage actif (fin, pause, reprise) | `TimeEntryReadModel::latestActiveOrFail()` |
| Créneaux publiés de la personne | `ShiftReadModel::publishedForEmployee()` |
| Règles de pause mises à plat pour l'écran | `WorkforceRuleResolver::breakRulesForClient()` |

Il reste au-dessus du seuil de 250 lignes de `ControllerSizeInvariantTest` (déjà le cas avant UX4). La seule extraction restante serait le contrôle de reprise anticipée de pause, une règle métier qui reste où elle est. Le seuil n'a pas été modifié. `LeaveController` passe de 309 à 311 lignes (deux contrôles d'une ligne).

## 4. Cohérence des durées d'absence

- **Méthode affichée** : « Estimation : 2 jours (14 h). Chaque jour du calendrier est compté ; jours fériés et jours non travaillés ne sont pas retirés. Corrigez si besoin. » Le champ s'appelle « Durée à décompter ». Rien ne laisse croire à un nombre légal.
- Avertissement conservé quand la période contient un samedi ou un dimanche.
- **Correction manuelle** conservée (pas d'une demi-journée). Recette : estimation 2 jours, correction à 1,5 → **150 centièmes envoyés et enregistrés**, et « 1,5 jour » affiché dans la confirmation d'approbation. Côté salarié : 3 jours affichés → 300 envoyés.
- Seul le dernier calcul demandé est appliqué (réponses tardives ignorées, UX4).
- Aucun calcul de jours ouvrés ajouté dans Vue : la règle reste celle de `LeaveDeductionCalculator`.
- Libellé : « 1,5 jour » (pluriel à partir de 2 en français).

**Risques (à traiter par le chantier métier du décompte)** :
1. Une absence du lundi au dimanche est estimée à 7 jours. Si la personne ne corrige pas, son solde est réservé puis consommé de 7 jours.
2. Les jours fériés ne sont jamais retirés (aucun calendrier par Market).
3. Les heures d'une absence partielle reposent sur « heures hebdomadaires du contrat ÷ 5 », quel que soit le rythme réel.
4. Un changement de dates après une correction manuelle relance l'estimation et remplace la correction.
5. Aucune trace ne distingue une durée estimée d'une durée corrigée.

## 5. Régression navigateur

| Recette | Résultat |
|---|---|
| `ux4-planning-time.spec.js` (6) : responsable 8 parcours avec écritures (dont correction manuelle de durée) ; salariée mobile, pointage complet ; salarié mobile, absence ; 8 profils ; entreprise sans Workforce ; tablette, mobile, sombre et anciennes routes | 6/6 |
| `workforce-m6a-navigation.spec.js` (4) : le test n°1 ouvre désormais le groupe repliable « Planning & temps » (navigation UX0 inchangée) | 4/4 |

## 6. Suite backend et build

| Étape | Résultat |
|---|---|
| Tests ciblés | `Ux41LeaveBalanceScopeTest` 8/8 ; self-service et pointage (`Ux4SelfLeavePreviewTest`, `EmployeeSelfShiftsTest` et tests appelant `/workforce/me`) 42/42 |
| Suite complète (une fois) | 4294 réussis, 12 ignorés, **3 échecs historiques** : `ControllerSizeInvariantTest` (taille, `DB` facade, `save()` dans des contrôleurs ; mêmes trois tests qu'avant UX4) |
| Build final | `pnpm build` propre |

## 7. Données

- Fiches et pointages créés par les recettes : supprimés (fiche 1766, pointage 1342 pour cette passe).
- **Une 4ᵉ demande annulée (id 73) s'ajoute sur la fiche de Bob** (parcours salarié rejoué une fois). Total : 68, 69, 71, 73, toutes annulées, conservées. Ce parcours en ajoute une à chaque exécution.
- Les 8 documents UX3 sont intacts.

## 8. Réserves restantes

1. `GET /workforce/leaves/statistics` : agrégats de toute l'entreprise visibles par tout détenteur de `leave_request`.
2. `EmployeeSelfController` (287 lignes) et `LeaveController` (311) restent au-dessus du seuil d'architecture. Les 3 échecs historiques de `ControllerSizeInvariantTest` subsistent.
3. Décompte en jours calendaires : voir les risques du §4 (chantier métier distinct).
4. Le parcours salarié de la recette ajoute une demande annulée à chaque exécution : il faudrait une fiche salariée de recette avec compte, créée et supprimée par l'exécution.
5. Réserves UX4 non traitées ici : plafond et report non appliqués, cockpit sans signalement du pointage ouvert, grille Semaine dense, redirections hétérogènes.
