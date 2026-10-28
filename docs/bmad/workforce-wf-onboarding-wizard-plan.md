# W-F — Wizard d'onboarding RH (plan, NON implémenté)

> Prépare le lot W-F sur la base de W-E (presets organisationnels). Aucun code ici — conception seulement.

## Objectif
À la première activation Workforce, guider la Company via un **wizard** (stepper Vuexy) qui transforme le preset résolu (W-E) en organisation opérationnelle sans saisie fastidieuse, puis amorce les paramètres RH. W-E fournit déjà profil + preview + apply ; W-F orchestre le parcours complet et multi-étapes.

## Étapes proposées (réutilise W-E + existant)
1. **Contexte** (lecture) : Market + JobDomain détectés ; proposition de `org_profile_key` (présélection depuis `OrgProfileRegistry`, ajustable). 
2. **Organisation** : `preview` du preset (W-E) → l'utilisateur confirme/ajuste (renommer avant apply, décocher des éléments) → `apply` non destructif.
3. **Rôles & accès** : proposition des rôles RH (réutilise `JobdomainGate`/presets de rôles + matrice RBAC ADR-634) ; attribution responsable RH/managers.
4. **Types de congés** : déjà seedés par Market (`LeaveTypeSeeder`) → confirmation.
5. **Règles de base** : pauses/temps de travail (moteur normatif existant `WorkforceRuleResolver`) → revue.
6. **Premier collaborateur** (optionnel) : raccourci vers la création, sans bloquer.
7. **Terminé** : `onboarding_dismissed_at` + résumé + liens.

## Principes
- **Aucune config exhaustive exigée** avant d'ajouter un collaborateur (sortie possible à chaque étape).
- **Idempotent & non destructif** (réutilise `apply` W-E) ; reprise possible (état de wizard persistable).
- **Permissions** : wizard réservé aux rôles administratifs (workforce.manage/admin).
- **UX** : stepper Vuexy, responsive, dark mode, i18n FR/EN ; pas de design-system parallèle.

## Dépendances / portée
- Dépend de W-E (resolver/preview/apply) ✅ livré. 
- Ne dépend PAS de W-Q (établissements) — fonctionne avec Department/JobRole.
- Décisions à confirmer : persistance de l'état du wizard (table légère vs client-only) ; présélection auto du profil (depuis `naf_code`/taille, **non déterminante** — l'utilisateur confirme).

## Tests prévus
Backend : enchaînement profil→preview→apply idempotent ; reprise ; RBAC. Frontend/E2E : parcours complet, sortie anticipée, responsive/dark, 0 erreur JS, aucun doublon après reprise.
