# OP-7 — Verrouillage des comptes DEV au mot de passe public (ADR-853)

**État** : préparé, **non appliqué**. Une autorisation explicite « GO OP-7 » est requise.

## Comptes visés (dump DEV du 2026-11-22, vérifié par `password_verify`)

| Table | Id | Compte | Mot de passe public | Décision |
|---|---|---|---|---|
| users | 1 | own…@leezr.test (propriétaire, société 1) | oui | verrouiller |
| users | 2 | adm…@leezr.test (société 1) | oui | verrouiller |
| users | 3 | bob…@leezr.test (société 1) | oui | verrouiller |
| users | 4 | tri…@leezr.test (propriétaire, société 2) | oui | verrouiller |
| users | 5 | pen…@leezr.test (propriétaire, société 3) | oui | verrouiller |
| users | 6, 7, 8 | comptes `leezr-test.invalid` et compte de recette 4.2 | non | hors périmètre |
| platform_users | 1 | **compte personnel du propriétaire** | **oui** | **exclu** ; mot de passe à changer par le propriétaire dans *Compte* |

Ces comptes n'ont aucun lien avec le déploiement : la CI se connecte par clé SSH (`VPS_SSH_KEY`) et n'utilise aucun compte applicatif.

## Simulation sur un clone du dump DEV : verte

Sauvegarde locale : `~/leezr-backups/dev-op7-*/` (dump complet, plus export JSON des lignes visées).

- Comptes verrouillés : 5.
- Sessions révoquées : 2, 0, 2, 1, 0.
- Jetons et réinitialisations : 0.
- Empreintes hors périmètre contrôlées : 15, écarts : 0.
- La transaction est ensuite annulée.

Pour chaque compte verrouillé, le script :
- remplace le mot de passe par une valeur aléatoire jamais affichée ;
- met `remember_token` à NULL ;
- supprime les sessions, les jetons Sanctum et les réinitialisations en attente.

Aucun rôle, aucune adhésion, aucune société n'est modifiée.

## Application (après GO OP-7, par l'agent, avec la clé `leezr-dev`)

1. Sauvegarde fraîche sur le serveur : `dev-preflight.sh` (section 2).
2. Export JSON des lignes visées, puis simulation sur DEV (transaction annulée) : `--only=users:1,2,3,4,5`.
3. Application : `--apply --confirm-database=c1leezrdev`.
4. Preuve : la simulation relancée doit répondre « REFUSED: users #1 no longer uses the public password ».

**Conséquence** : la recette DEV ne pourra plus utiliser ces comptes de démonstration. La société de recette 4.2 (id 4), qui a un mot de passe aléatoire, reste disponible.
