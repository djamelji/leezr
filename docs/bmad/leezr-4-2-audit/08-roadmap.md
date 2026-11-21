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

## Suivi d'avancement (mis à jour 2026-11-21)

> Les missions livrées par le propriétaire ne suivent pas exactement le découpage ci-dessus : la mission « M3 » validée regroupe des éléments des lignes M3, M4 et M5. Statut réel par constat :

| Mission livrée | Commit local | Constats **terminés** | Constats **partiels / restants** |
|---|---|---|---|
| M1 (ADR-837) | `b6b38959` | UXC-01, UXP-01, UXP-07 | — |
| M2 (ADR-838) | `fb0fe811` | SEC-05, SEC-06, SEC-07 (+ PasswordPolicy en libre-service, fermeture des sessions) | SEC-09, SEC-10, SEC-12 hors périmètre validé : **restent ouverts** |
| M3 (ADR-839) | `0a1f8fcb` | UXC-02 (page « module indisponible »), UXP-04 (page 403 Platform), UXC-03 (libellé « Configuré »), UXP-03 / SEC-08 pour les refus relevés par l'audit (onglets Activité, Funnel, Coupons, Revue tarifaire ; sélecteurs du formulaire Coupons), fiche société refusée sans données, 403 Company sans redirection automatique | **SEC-04 / FUN-04** : voir M3.1 ; UXC-05 / FUN-03 : la page indique l'offre requise et mène aux offres, mais l'aperçu de prix « depuis la fonction » n'est pas ajouté (l'onglet Offre existant le fait) ; UXC-07 → M3.1 ; widgets du tableau de bord : seuls ceux relevés par l'audit sont couverts, pas de déclaration générique widget ↔ permission |
| M3.1 (ADR-840) | `33bfe9a2` | **UXC-07** (Réglages Workforce en lecture seule) ; droits « en attente de validation » distincts du refus (ADR-808) ; **SEC-04 / FUN-04 préparé** : diff exact des 8 rôles, appliqué + restauré + réappliqué **sur le clone** | **SEC-04 / FUN-04 : application DEV puis PROD = nouveau GO** (`m3-1/README.md`) |
| M4 (ADR-841) | `109ea296` | **UXP-08 / FUN-08** (MRR unique : tableau de bord, Facturation, widget MRR, liste et total des sociétés ; ARR = 12 × MRR) ; libellés financiers explicites (MRR / facturé HT / encaissé TTC / reste dû) + aide ; unité des widgets du grand livre (× 100) corrigée ; montants financiers de la liste réservés à `view_billing` | Historique MRR reconstruit (pas d'instantané) ; règle `REVENUE` proratisée en cas de crédit portefeuille à arbitrer ; churn / conversion non revus ; FUN-05 / UXP-06 (roadmap M6 d'origine) non traités |
| M5 (ADR-842) | `43dbb42f` | **UXP-05** (Facturation 15 onglets → 5 groupes, adresses conservées), **UXP-06** (fiche entreprise sans doublon ; changement d'offre dans « Abonnement et facturation »), **UXP-09**, **UXP-10**, **UXP-12**, **UXP-14** ; UXP-11 sur les listes Entreprises / Utilisateurs | UXP-13 (Catalogue : modèles de contrat, marchés), UXP-11 ailleurs (`IconBtn` @core), UXP-15, UXP-02 |
| M6 (ADR-843) | `2fe468dd` | **UXC-04** (menu Personnes, une action « Ajouter » à deux choix, vocabulaire d'accès unique, nom manquant), **UXC-06** (requalifié + garde FR), **UXC-08**, **UXC-09** (accueil) ; livraison chauffeur en un geste de moins | FUN-11 (rôles en anglais, décision C4), bouton `@core` des tiroirs, FUN-06 (export temps / absences) |
| M7 (ADR-844) | `a0c7d74e` | **FUN-06** (temps / absences) : export pointages, pauses, prévu/réalisé (feuilles de temps), planning, absences — aperçu, CSV, permissions et journal | XLSX (aucune bibliothèque), export des expéditions (FUN-06 « 3 ») |
| M8 (ADR-845) | `9b145684` | **FUN-01 / FUN-02** : page « Données et fin de contrat », export complet ZIP + manifeste (propriétaire, mot de passe, expiration, révocation, purge), annulation de résiliation côté Company, supervision Platform + segment « Fin de contrat » | durées de conservation par marché, suppression définitive, archivage, export pendant suspension (décisions D-M8-1→8) |
| M8.1 (ADR-846) | `e8001ac3` | Export pendant suspension (seul ce parcours s'ouvre), statuts complet / partiel / échoué avec données absentes affichées avant téléchargement, procédure exceptionnelle Platform (droit dédié, double validation, remise au membre désigné) — **exécution désactivée** | D-M81-1 (preuves d'autorité), D-M81-2 (destinataire externe), R-M81-1 et R-M81-2 corrigés en M8.2 |
| M8.2 (ADR-847) | `da315d7b` | Connexion Platform : clé de rôle longue (journal `actor_role` 255, `actor_type` catégorie contrôlée, historique conservé) ; mode Company suspendue réellement utilisable (aucune requête refusée, menu réduit, routes limitées, message traduit, reconnexion et réactivation) | — |
| M9 (ADR-848) | `adee4bf7`…`dae827b8` | **Assainissement technique** : 13 contrôleurs sous 250 lignes (ControllerSizeInvariantTest vert, aucune liste d'exceptions élargie), doublons consolidés (PDF de facture, périmètre planning, opérations annulables, refus métier, invitations de signature…), `/api/whoami`, fichier inerte et 9 dépendances supprimés, laboratoires hors build de production, script destructif gardé ; PHPUnit 4 882 verts / 0 échec | `register/funnel-track` (D), clés de traduction dynamiques (D), quasi-doublons signature (machine d'états) |
| M0 | — | — | **Incident SEC-01/02/03 toujours ouvert (propriétaire)** |

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
