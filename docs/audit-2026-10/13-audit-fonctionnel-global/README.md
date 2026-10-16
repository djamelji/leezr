# Audit fonctionnel global de Leezr (octobre 2026) — dossier de suivi

> Évaluer Leezr **comme un SaaS B2B réellement utilisé**, pas seulement comme du code qui compile. Deux phases : A (audit documenté) → B (corrections autonomes P0/P1). Ce dossier est **incrémental** : il démarre par l'anomalie prioritaire (prochaine facture) puis s'élargit lot par lot. **Il ne prétend pas** avoir testé tout le produit en une passe.

## Contenu
| Fichier | Objet |
|---|---|
| `04-anomalie-prochaine-facture.md` | **Investigation + correction** de « Prochaine facture : 31 mai 2026 » (P1, corrigé) |
| `02-matrice-parcours.md` | Matrice parcours × rôle × méthode de vérification |
| `03-registre-anomalies.md` | Registre P0/P1/P2/P3 (consolidé + nouveaux findings) |
| `06-resultats-e2e.md` | Résultats des tests E2E Playwright (staging, lecture seule) |
| `10-synthese-maturite.md` | Synthèse de maturité fonctionnelle par domaine |

## Réutilisation des audits antérieurs (ne pas répéter)
La cartographie fonctionnelle et les invariants sont **déjà** établis — on s'y réfère :
- `../02-inventaire-fonctionnel.md` (cartographie Platform/Company/Employee)
- `../03-registre-anomalies.md` (registre initial FUNC-*/SEC-*/…)
- `../09-validation-invariants-architecturaux.md` (1 Company = 1 JobDomain, multi-tenant, RBAC)
- `../10-audit-finance-facturation.md` + `../11-remediation-finance-matrice.md` (billing)
- `../12-scheduler-cockpit-integration.md` (automations)

## Légende « niveau de vérification » (obligatoire sur chaque ligne de matrice)
- **[NAV]** vérifié dans le navigateur (E2E Playwright).
- **[API]** vérifié par appel API réel.
- **[TEST]** vérifié par test automatisé (PHPUnit).
- **[CODE]** vérifié uniquement par lecture de code.
- **[NON]** non vérifié.

## Garde-fous respectés
Aucune écriture comptable historique modifiée · aucune opération destructive/massive sur STAGING/PROD · aucun paiement réel · lecture seule sur `owner@leezr.test` (données partagées) · pas de push/déploiement sans autorisation explicite · architecture inchangée (Laravel+Vue3+Vuetify3, Sanctum SPA, multi-tenant, Markets/JobDomains, 1 Company = 1 JobDomain).

## État d'avancement (honnête)
- ✅ **Anomalie prioritaire** (prochaine facture) : investiguée, corrigée à la source + logique + UI, testée, sur 2 surfaces (onglets Plan **et** Overview).
- ✅ **E2E réel** exécuté contre STAGING (lecture seule) : 19/20 verts ; 1 finding (coupons, à confirmer).
- 🔄 **Audit produit intégral** : **en cours** — la grille de contrôle (§5 de la mission) est appliquée au périmètre Billing ; les autres domaines restent à parcourir en lots suivants. **Non déclaré complet.**
