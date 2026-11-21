# Leezr 4.2 — M4 : cohérence financière Platform (ADR-841)

Base de recette : clone marqué `leezr_audit842` ; montants attendus recalculés en SQL brut, indépendamment du code (`scripts/audit842-m4-expected.php` → `attendu-sql-clone.json`). L'écart 174 €/261 € a été recalculé sur la base locale `leezr` en **lecture seule** (`ecart-174-261-base-locale.txt`). Aucune facture, écriture du grand livre, abonnement ou tarif modifié.

## L'écart 174 € / 261 € (audit UXP-08)

| | Liste des sociétés (avant) | Tableau de bord / Facturation (avant) |
|---|---|---|
| Formule | Σ **prix public mensuel** du plan de chaque société **active** | Σ **prix contracté** des abonnements **actifs / en retard** (plan seul) |
| Sociétés comptées | 9 sociétés « Pro » × 29 € = **261 €** : 1 abonnée, **2 en essai**, **6 sans abonnement** | 6 abonnements × 29 € = **174 €** |
| Oubliés | 5 sociétés « Starter » abonnées à 29 € contractés (prix public Starter = 0 €) | options (382 €/mois) — mais comptées dans l'ARR |

Les deux chiffres mesuraient des choses différentes ; aucun n'était le revenu récurrent réel. Le second était le plus proche mais excluait les options du MRR tout en les ajoutant à l'ARR (ARR ≠ 12 × MRR), et ces options incluaient des sociétés en essai ou sans abonnement.

## Indicateurs — avant / après

| Indicateur | Écran(s) | Définition AVANT | Définition APRÈS |
|---|---|---|---|
| **MRR** | Tableau de bord (Pilotage commercial), Facturation, widget « MRR », liste et total des sociétés | 3 calculs différents (prix public / contracté plan seul / widget vide `null`) | **Un seul** (`BillingMetricsCalculationService::recurringPortfolio`) : abonnements facturés (actifs + en retard) → prix contracté + options actives − remises récurrentes (règle `CouponService` du moteur), annuel ÷ 12, HT, par devise. Exclus : essais, sans abonnement, résiliés, changements programmés non effectifs, proratas, taxes, portefeuille |
| **ARR** | Tableau de bord, Facturation | (plan + options de **toutes** les sociétés) × 12 ≠ 12 × MRR affiché | **MRR × 12** |
| dont options | Facturation | options actives de toutes les sociétés (« Revenus addons ») | options des seuls abonnements facturés, « dont options (HT / mois) » |
| Abonnements facturés | Facturation, tableau de bord | actifs + en retard (tableau de bord) ; idem Facturation | idem (un double comptage introduit pendant la recette a été corrigé) |
| **Facturé** (HT) | widget « Facturé ce mois », tendance, ratio de remboursement | grand livre `REVENUE` (HT, à l'émission) — **affiché × 100** (130 326 € au lieu de 1 303,26 €) | même source, unité corrigée (`ledgerAmountDisplay`) ; libellé « Facturé ce mois (HT) » au lieu de « Chiffre d'affaires du mois » |
| **Encaissé** (TTC) | tendance d'encaissements, derniers paiements | grand livre `CASH` ; listes « derniers paiements / factures / avoirs » **× 100** | unité corrigée ; libellé « Encaissements par jour (TTC) » |
| **Reste à encaisser** (TTC) | widget créances | grand livre `AR` (correct depuis ADR-582) | libellé « Reste à encaisser (TTC) » |
| Remboursé | ratio de remboursement | grand livre `REFUND`, affiché × 100 | unité corrigée |

Chaque indicateur porte une aide « i » (ce qui est compté, HT/TTC, période) ; la Facturation affiche l'heure de dernière actualisation ; les jeux de données mis en cache (10 min) renvoient `computed_at`.

## Différences légitimes entre écrans

- Le widget du tableau de bord arrondit à l'euro (363 € pour 362,83 €) ; la Facturation et la liste affichent les centimes.
- MRR ≠ facturé ≠ encaissé : un abonnement annuel facturé en janvier donne 1/12 de MRR chaque mois mais une seule facture ; une facture impayée est « facturée » mais pas « encaissée » ; un essai n'apporte ni MRR ni facture.
- Le « facturé » tient compte du portefeuille comme le grand livre (voir risques).

## RBAC

Montants financiers seulement avec `view_billing` : `/billing/*` (existant) **et désormais la liste des sociétés** (API : `mrr` / `total_mrr` = `null` sans ce droit ; colonne et carte masquées). Le catalogue des plans n'est plus demandé sans `manage_plans` (refus 403 évitable supprimé).

## Recette navigateur (`tests/e2e/audit842-m4-recipe.spec.js`) — 3/3

Super-administrateur : MRR identique (SQL indépendant 362,83 €) sur Facturation, tableau de bord et liste ; ARR = 12 × MRR ; options 123,00 € ; facturé du mois 1 303,26 € (= grand livre) ; même nombre d'abonnements sur les deux écrans ; aide « i » ; « Essai » au lieu d'un montant. Finance : indicateurs visibles, liste des sociétés refusée. Lecture sociétés sans finance : aucune donnée financière à l'écran ni dans l'API. Aucune erreur JS ni refus d'API inattendu.

## Risques résiduels

- Historique du MRR **reconstruit** à partir des abonnements actuels (pas d'instantané stocké, ADR-747) : churn et changements passés non reflétés ; les options sont celles d'aujourd'hui.
- Multi-devises : MRR par devise disponible (`mrr_by_currency`), total affiché dans la devise de reporting sans conversion (inchangé).
- Grand livre : quand un crédit portefeuille réduit le montant dû, `REVENUE` (HT) est proratisé sur le montant dû — le « facturé HT » peut donc être inférieur au sous-total des factures payées par portefeuille. Règle comptable existante (ADR-582/F3) non modifiée ; à arbitrer.
- Remises : seules les remises récurrentes portées par l'abonnement (coupon) entrent dans le MRR ; un coupon ponctuel appliqué à une facture n'y entre pas (c'est du facturé).
- « Taux de churn » et « conversion d'essai » conservent leur définition existante (non revue dans M4).
