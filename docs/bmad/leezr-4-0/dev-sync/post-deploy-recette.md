# Recette courte après synchronisation du DEV distant (dev.leezr.com)

> Après déploiement (qui inclut désormais `system:sync`, ADR-832). Durée cible : 60 à 90 min. Pas de campagne E2E
> sur le serveur (ADR-831 : les specs exigent un clone marqué). Chaque ligne : résultat observé + capture si écart.
> **L** = lecture seule (aucune écriture hors session de connexion). **É** = écriture, **uniquement** dans la société
> fictive dédiée `Recette RC2 <date>` créée en P1 ; jamais dans une société existante.

## P0 — Santé (L, 5 min, poste local)

| # | Contrôle | Attendu |
|---|---|---|
| P0.1 | `curl -s -o /dev/null -w '%{http_code}' https://dev.leezr.com/up` | 200 |
| P0.2 | `curl -s https://dev.leezr.com/api/public/version` | `{"version":"<APP_BUILD_VERSION du déploiement>"}` |
| P0.3 | `curl -s -H 'Accept: application/json' https://dev.leezr.com/api/realtime/stream -w ' %{http_code}'` | JSON 401 (pas de redirection 302) |
| P0.4 | `curl -s -o /dev/null -w '%{http_code}' https://dev.leezr.com/api/_recipe/identity` | 404 (route absente en production, ou clone non marqué) |
| P0.5 | serveur : `php artisan migrate:status \| grep -c Pending` ; `config:show billing.async_queues_enabled` ; `config:show realtime.driver` | 0 ; false ; null |
| P0.6 | serveur : rapport `shared/storage/logs/system-sync-<horodatage>.json` du déploiement ; `php artisan system:sync --dry-run` | conflits = ceux du clone ; aucun `evolved`/`adopted` non décidé ; aucune écriture hors conflits au 2ᵉ passage |

## Platform (compte administrateur Platform)

| # | Parcours | Type | Attendu |
|---|---|---|---|
| A1 | Connexion, tableau de bord | L | aucun 5xx, états vides lisibles |
| A2 | JobDomains › Logistique | L | « Proposé à l'inscription » actif ; rôle chauffeur recommandé = moindre privilège **si** `--adopt=jobdomains:logistique:default_roles` a été décidé, sinon valeur conservée |
| A3 | Presets | L | familles logistique publiées (catalogue, planning, RBAC, exigences) |
| A4 | Modules | L | `workforce_referral` présent (installé par `system:sync`) ; dépendances affichées ; activations globales inchangées |
| A5 | Plans | L | prix, essais et limites **identiques** au relevé R-audit (aucun changement de tarif) |
| A6 | Sociétés | L | nombre = relevé R-audit ; société 1 et autres intactes |
| A7 | Facturation | L | onglets sans erreur ; aucune relance déclenchée hors calendrier ; `jobs` sans `billing*` |
| A8 | Tâches planifiées | L | nouvelles tâches listées ; dernière exécution sans erreur |

## Company — lecture (compte propriétaire existant)

| # | Parcours | Attendu |
|---|---|---|
| C1 | Connexion, accueil `/home` | une seule entrée « Accueil » |
| C2 | Équipe › liste, fiche, rubriques | sections décidées par le serveur |
| C3 | Planning (Semaine, Jour, Postes, Mois) | rendu, aucune 5xx |
| C4 | Temps & pointages, Absences, Préparation de la paie | lecture sans erreur |
| C5 | Expéditions, Mes livraisons (chauffeur) | listes cohérentes |
| C6 | Documents (à traiter, collaborateurs, signatures) | rendu |
| C7 | Navigation depuis un onglet (ex. Absences › Types) vers « Préparation de la paie » | arrive sur l'onglet par défaut (correctif ADR-831) |

## RBAC et isolation (L)

| # | Profil | Attendu |
|---|---|---|
| R1 | Chauffeur | pas de paie collective (403) ; fiche d'un collègue sans section « Accès » |
| R2 | Responsable exploitation | Équipe/Contrats visibles mais limités à son périmètre ; pas d'Absences ni de Paie |
| R3 | Gestionnaire paie | seulement la paie |
| R4 | Changement de société | menus et données suivent ; requête avec `X-Company-Id` d'une société non membre → refus |

## Écritures — société fictive dédiée uniquement (É)

| # | Parcours | Attendu |
|---|---|---|
| P1 | Inscription Logistique d'une société fictive `Recette RC2 <date>` (adresse e-mail de recette) | société créée avec les presets publiés ; aucun paiement réel (plan gratuit ou sandbox sans carte) |
| P2 | Onboarding Company (assistant modules) | étapes adaptatives, modules activés selon dépendances |
| P3 | Équipe › Ajouter une personne (sans compte) puis Embaucher | fiche + contrat brouillon |
| P4 | Planning › créneau sur un jour couvert | dialogue de confirmation de sureffectif depuis le tiroir (correctif ADR-831) puis créneau créé |
| P5 | Publication de la semaine ; pointage entrée/sortie du collaborateur fictif | états persistés |
| P6 | Congé : demande puis approbation | solde mis à jour |
| P7 | Préparation de paie : période ouverte, **sans** calcul définitif | aucun bulletin émis |
| P8 | Expédition : création, affectation, statut | persistés |
| P9 | Document : dépôt d'un PDF de test | classé ; si `DOCUMENT_SCANNER=none`, un dépôt d'onboarding candidat reste en quarantaine (attendu) |
| P10 | Fin : suspendre la société fictive depuis Platform | aucune autre société touchée |

## Clôture

- `storage/logs/laravel.log` : aucune 5xx nouvelle ; `failed_jobs` : stable ; `jobs` : aucun `billing*`.
- Comptages R-audit des sociétés existantes inchangés (hors société fictive).
- Verdict consigné dans le rapport de synchronisation (GO DEV / retour arrière).
