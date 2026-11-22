# Leezr 5.0 · recette navigateur sur DEV (ADR-862)

**Date** : 2026-11-22. **Cible** : `https://dev.leezr.com`, release `20261122205231_ce098d0`. **Comptes** : les comptes fictifs `@leezr.test`, sur GO du propriétaire. Le compte Platform n° 1 n'a pas été utilisé.

**Méthode** : un script Playwright autonome, en lecture seule, sur Chrome réel (`channel: chrome`), hors de la suite E2E, qui reste refusée sur DEV.

- Connexion par le vrai formulaire, puis navigation uniquement : aucune action cliquée.
- Le script relève :
  - les erreurs JavaScript et console ;
  - toute réponse API ≥ 400 ;
  - toute requête d'écriture ;
  - le débordement horizontal ;
  - les libellés tronqués ;
  - les graphiques vides.

## Couverture : 149 chargements de page, 0 échec de connexion

| Compte | Rôle | Pages | Variantes |
|---|---|---|---|
| owner | propriétaire, société 1 | 17 : accueil, Personnes, fiche et profil, Planning, Temps, Documents (à traiter, conformité, signatures), Expéditions, Réglages, Modules, Paramètres RH, Facturation, profil société | ordinateur clair et sombre, mobile 390 clair et sombre, tablette 1024 |
| admin | Dispatcher, société 1 | les mêmes 17 | ordinateur clair, mobile sombre |
| bob | Driver, société 1 | 10, dont Mon espace et Mes livraisons | ordinateur clair, mobile sombre |
| trial | propriétaire, société 2 | 5 | ordinateur clair, mobile sombre |

Les captures sont dans `captures/dev-recette/` (`<compte>-<thème>-<format>-<page>.jpg`) ; les mesures brutes, dans `results.json`.

## Résultats

- **0** erreur JavaScript et **0** erreur console sur les 149 chargements.
- **0** appel API refusé : aucune réponse ≥ 400.
- **0** écriture. La seule requête POST est `POST /api/dashboard/widgets/data`, une lecture groupée des widgets (`batchResolve`).
- **0** débordement horizontal de page, ni sur mobile ni sur tablette.
- Thèmes clair et sombre appliqués sur toutes les pages. Les titres d'onglet sont spécifiques, par exemple « Accueil · Leezr Logistics · Leezr » ou « Personnes · Équipe · Leezr ».
- **Permissions** : les refus sont cohérents avec la navigation de chaque rôle.
  - Dispatcher : pas de Planning, Temps, Modules, Facturation ni Paramètres RH. Les entrées sont absentes du menu, et l'adresse directe mène à la page « Accès refusé ».
  - Driver : pas d'Expéditions de gestion, Documents, Modules ni Facturation. Il a Mon espace et Mes livraisons.
- **Redirections attendues** :
  - `/company/people/user-3` mène à `employee-3`, l'adresse canonique ;
  - `/company/workforce` mène à l'accueil unique ;
  - `/company/workforce/employees` mène à Gestion RH.

## Anomalies (visuelles, aucune fonctionnelle)

| # | Écran | Constat | Gravité |
|---|---|---|---|
| A1 | Expéditions, mobile 390 | Le tableau est trop large : la colonne Trajet est écrasée en colonne étroite et haute, et le filtre « En transit » est coupé. Il faudrait un rendu en liste sur mobile. | P2 |
| A2 | Mon espace (Driver), mobile | Onglets tronqués : « Mes docu… », « Mes bulleti… », « Mes signat… ». Il manque les libellés courts (`short`) déjà utilisés pour Documents et la fiche. | P3 |
| A3 | Documents, Conformité et Signatures, tablette 1024 | Libellés de la bande d'indicateurs tronqués (« Taux de conformité », « En attente de signature ») : le retour à la ligne n'est actif que sous 599 px. | P3 |
| A4 | Personnes, mobile | La 3ᵉ section, « Rôles et autorisations », est à moitié visible au bord : elle s'atteint par défilement horizontal, mais rien ne le signale. | P3 |

## Platform

Recette **non réalisée** : aucun compte Platform de recette n'existe sur DEV. La procédure de création, limitée et révocable, est dans [PLATFORM-RECIPE-ACCOUNT-DEV.md](PLATFORM-RECIPE-ACCOUNT-DEV.md).
