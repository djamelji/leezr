# Matrice des modèles de temps de travail — état actuel et décisions à prendre (ADR-825)

> Audit en lecture seule du code DEV au 18/11/2026.
> - **Aucune règle de calcul ni d'exemption n'a été ajoutée.**
> - Seul un diagnostic a été ajouté (lecture) : le régime du contrat de chaque salarié exclu d'une période.

## 1. Ce qui existe

| Élément | Source | Valeurs |
|---|---|---|
| **Modèle de temps de travail** | `EmploymentContract::WORK_MODELS` (champ `work_model_key`, validé à la création du contrat) | `horaire`, `forfait_jours`, `forfait_heures` |
| **Durée hebdomadaire** | Contrat (`weekly_hours`, 35 par défaut) | Tout nombre, 0 à 99 |
| **Type de rémunération** | Plan de rémunération (`compensation_type`) | `monthly`, `hourly`, `daily` |
| **Règles Market** (FR) | `market_rule_sets`, domaine `workforce` | `daily_hours_max`, `weekly_hours_max`, `weekly_hours_legal`, `rest_daily_min`, `rest_weekly_min`, `break_after_hours`, `annual_leave_days`, `min_wage_hourly_cents` |
| **Règles Company** | `WorkforceRuleResolver` / politiques (`overtime_daily_threshold`, `weekly_hours_reference`, pause minimale) | Surcharges par société |
| **Type de contrat** | `contract_type` | `cdi`, `cdd`, `stage`, `alternance`, `freelance` — **n'influence aucune règle de temps** (conforme : le libellé du contrat ne doit pas décider) |

**Donnée hors liste constatée sur DEV** : 1 contrat (société 21) avec `work_model_key = on_site`, valeur que la validation actuelle refuserait. Elle vient probablement d'un import ou d'une donnée antérieure. **À corriger par la société**, non modifié ici.

## 2. Comportement actuel, par étape

| Étape | `horaire` | `forfait_heures` | `forfait_jours` | Temps partiel (`weekly_hours` < légal) |
|---|---|---|---|---|
| **Planning** (créneaux) | Oui | Oui | Oui (aucune distinction) | Oui |
| **Pointage** (arrivée, pause, fin) | Proposé à tous | Proposé | Proposé (aucune distinction) | Proposé |
| **Feuille de temps** (`GenerateTimesheetUseCase`) | Générée à partir des pointages | Idem | Idem (**aucune distinction**) | Idem |
| **Heures supplémentaires** de la feuille | Seuil jour = règle / politique / hebdo ÷ 5 ; seuil semaine = référence (règle → contrat → 35) | **Comptées comme pour l'horaire** | **Comptées comme pour l'horaire** | Seuil = durée du contrat : les heures au-delà sont comptées comme **supplémentaires** (pas de notion d'heures complémentaires) |
| **Contrôle de conformité** (`WorkTimeComplianceChecker`) | Durées max et repos contrôlés | Durées max « non vérifiable — régime non encore qualifié » | Idem | Contrôlé comme l'horaire |
| **Préparation de paie : inclusion** (`ComputePayrollUseCase`) | Feuille **verrouillée** couvrant la période **obligatoire**, sinon exclusion (`missing_timesheet`) | **Idem** | **Idem** | Idem |
| **Préparation de paie : base** | Selon le **type de rémunération** : `monthly` = salaire de base ; `hourly` = heures travaillées × taux ; `daily` = jours travaillés × taux journalier | Idem | Idem (les jours sont comptés à partir des pointages > 0 min) | Idem ; heures mensuelles = hebdo × 52 / 12 |
| **Préparation de paie : heures supplémentaires** | Valorisées (taux horaire × majoration) | **Valorisées** si la feuille en contient | **Valorisées** si la feuille en contient | Valorisées comme supplémentaires |

## 3. Écarts identifiés (non corrigés, décision requise)

1. **Forfait jours** : feuille de temps horaire obligatoire pour être payé. Les pointages produisent des heures supplémentaires qui seraient **valorisées**. En droit français, le forfait jours se suit en jours travaillés et repos, sans heures supplémentaires.
2. **Forfait heures** : les heures supplémentaires sont calculées sur le seuil standard, pas sur le volume du forfait.
3. **Temps partiel** : aucune distinction entre heures complémentaires (régime et majoration propres, limite du dixième ou du tiers) et heures supplémentaires.
4. **Mensualisés** (`monthly`) : le paiement de base ne dépend pas des heures, mais l'**absence de feuille verrouillée exclut** quand même le salarié. Pour un horaire mensualisé, la feuille sert aux heures supplémentaires et aux absences. Pour un forfait jours, elle n'a pas de sens sous forme horaire.
5. **Valeur de modèle hors liste** en base (`on_site`) : aucune règle ne s'applique.
6. **Type de rémunération × modèle** : aucune cohérence vérifiée (par exemple `forfait_jours` + `hourly`).

## 4. Matrice cible proposée (à valider, NON implémentée)

| Modèle | Document de temps requis pour la paie | Contenu attendu | Heures sup. / complémentaires | Exclusion si absent |
|---|---|---|---|---|
| `horaire` temps plein | Feuille horaire verrouillée | Heures, pauses, absences | Heures supplémentaires (seuils Market / Company) | Oui (comme aujourd'hui) |
| `horaire` temps partiel | Feuille horaire verrouillée | Idem | **Heures complémentaires** jusqu'à la limite légale, puis règles propres | Oui |
| `forfait_heures` | Feuille horaire verrouillée | Heures | Au-delà du **volume du forfait** seulement | Oui |
| `forfait_jours` | **Relevé de jours** (jours travaillés / repos / absences) verrouillé, pas une feuille horaire | Jours | **Aucune** heure supplémentaire | À décider : relevé de jours requis, ou paie mensualisée sans document avec absences seules |

## 5. Décisions nécessaires avant toute implémentation

- **D1** : forfait jours, quel document justifie la paie (relevé de jours ou aucun) ?
- **D2** : forfait jours, faut-il bloquer le calcul et la valorisation des heures supplémentaires ?
- **D3** : temps partiel, faut-il introduire les heures complémentaires (règles Market : limite, majorations) ?
- **D4** : forfait heures, quel seuil d'heures supplémentaires (volume du forfait) ?
- **D5** : cohérence modèle × type de rémunération, à valider à la création du contrat ?
- **D6** : contrats existants hors liste (`on_site`), quelle correction ?

## 6. Diagnostic ajouté (ADR-825, sans effet sur le calcul)

- La période de paie expose `anomaly_work_models` (régime du contrat courant de chaque salarié cité dans les anomalies).
- L'écran affiche le régime à côté des salariés exclus (« Paul (forfait jours) ») : la RH voit tout de suite les cas relevant de D1 à D4.
