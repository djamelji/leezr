# Leezr 4.2 — 08 · Roadmap coordonnée

> Ordre fondé sur : risque d'abord, puis ce qui débloque le plus d'autres corrections, puis la finition. Chaque mission est **réalisable seule**, livrée avec tests, ADR et recette navigateur. **Rien n'est lancé avant validation des priorités par le propriétaire.**
> Efforts : XS < ½ j · S ≈ 1 j · M ≈ 2–3 j · L ≈ 1 sem.

## Vue d'ensemble

| Ordre | Mission | Contenu (constats) | Effort | Débloque |
|---|---|---|---|---|
| **M0** | Clôture de l'incident de sécurité | SEC-01, SEC-02, SEC-03 — actions du propriétaire, une à la fois, preuve à chaque étape | propriétaire | Tout déploiement PROD |
| **M1** | Correctifs immédiats | UXC-01 (URL timeline, 1 ligne) + test de contrat « chaque URL appelée par le front existe » · UXP-01 (retirer le changement de plan de la liste) · UXP-07 (titres/i18n tableau de bord) | S | M4 |
| **M2** | Durcissement sécurité | SEC-05, 06 (2FA : OTP pour désactiver / régénérer), SEC-07 (audit mot de passe membre), SEC-09 (jeton métriques), SEC-10 (polices), SEC-12 (secret en-tête) | S–M | — |
| **M3** | Droits des sociétés existantes | SEC-04 / FUN-04 : appliquer la remédiation de rôles ADR-823 aux sociétés existantes via le moteur de presets (simulation → DEV → décision PROD) | M | — |
| **M4** | R1 « ce qu'on voit = ce qu'on peut » | UXP-03, UXP-04, SEC-08, UXC-07 : déclaration de permission pour onglets et widgets, page 403 Platform | M | M5, M6 |
| **M5** | R2 « fonction indisponible » + montée en gamme | UXC-02, UXC-03, UXC-05, FUN-03 : composant unique 4 états, garde de route de module, carte « Disponible avec … » | M | — |
| **M6** | R3 + indicateurs commerciaux | FUN-05, UXP-08/FUN-08 (MRR unique), UXP-06 (fiche société) | M | — |
| **M7** | Rangement des menus Platform | UXP-05 (Facturation en 3 groupes), UXP-13 (Catalogue), UXP-14 (onglets conditionnels), UXP-02/TEC-15 (fiche module lisible sans synchro) | M | — |
| **M8** | Cycle de vie et réversibilité | FUN-01 (procédure de clôture + conservation), FUN-02 (export complet à la demande), FUN-13 | L | Lancement commercial |
| **M9** | Finition Company | UXC-04 (Équipe : une action « Ajouter », statuts d'accès, rôles traduits — décision C4), UXC-06, UXC-08, UXP-09, UXP-10 | S–M | — |
| **M10** | Accessibilité des composants partagés | UXP-11, UXP-15, UXC-09 : `IconBtn` avec nom + infobulle, contrastes, libellés de champs | M | — |
| **M11** | Assainissement contrôlé | TEC-02, 03, 04, 05, 06, 07 (après vérification), 10, 12, 14, dépendances front | M | — |
| **M12** | Exports opérationnels & assistance | FUN-06 (temps/absences), FUN-07 (accès assisté en lecture seule, audité, avec accord du client) | M–L | — |

## Regroupements obligatoires (à faire ensemble)

- **M4 avant M5** : la page « fonction indisponible » réutilise la déclaration de permission de R1.
- **UXP-01 (M1) + FUN-05 (M6)** : on retire d'abord l'action dangereuse, puis on fournit le bon chemin (fiche › Facturation avec aperçu).
- **SEC-04 (M3) + UXC-04 (M9)** : les rôles corrigés changent ce que voit l'équipe ; la page Équipe est revue après.
- **UXP-08 (MRR)** : une seule définition, appliquée simultanément au tableau de bord, à la Facturation et à la liste des entreprises.
- **M11** ne supprime rien sans la preuve demandée dans 05 ; les alias (TEC-01) restent 12 mois.

## Avant un lancement commercial (minimum)

M0 · M1 · M2 · M3 · M6 (MRR, plan) · M8 (procédure de clôture + export à la demande). Le reste améliore la qualité perçue sans bloquer.

## Non traité par cet audit (prochaine campagne)

- Pages non testées (01 §5) : détails de workflow, DSN, période de paie, temps — nécessitent des données de recette dédiées.
- Lecture seule de la configuration réelle DEV/PROD (rôles résiduels, comptes de démonstration) — sur autorisation.
- Prestataires réels (Stripe, signature qualifiée) — environnements sandbox absents.
