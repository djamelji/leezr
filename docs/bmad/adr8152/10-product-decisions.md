# ADR-815.2 — 10. Décisions produit attendues

> Chaque décision bloque ou oriente un lot de `08`. La recommandation est un **choix de conception** argumenté, pas une décision prise.

| # | Décision | Options | Recommandation | Lot | Preuve à l'origine |
|---|---|---|---|---|---|
| **D1** | Un salarié voit-il des chiffres collectifs (présents, congés en attente des autres) ? | a) non ; b) oui sur droit explicite | **a**, avec b possible par permission dédiée | L0, L5 | Agrégats servis au salarié (P0) |
| **D2** | Combien d'accueils ? | a) `/home` unique à blocs ; b) deux pages distinctes | **a** ; `/dashboard` devient un alias | L5 | Même grille sur les deux pages |
| **D3** | Que montre l'annuaire à un profil sans droit de gestion ? | a) nom, photo, poste ; b) rien ; c) l'état actuel | **a** | L0 | Le chauffeur lit e-mail de connexion, rôle et indicateur administrateur |
| **D4** | Périmètre de « voir les salariés » | a) rendre explicite la règle actuelle (`view` = équipe, `view`+`manage` = entreprise) ; b) créer une permission « entreprise » distincte | **a** maintenant (pas de changement de règle) ; b à étudier | L1 | RH sans `manage` ne voit aucune fiche |
| **D5** | Poste obligatoire ? | a) pour les nouvelles embauches ; b) pour tous ; c) facultatif | **a** | L1, L2 | 45 salariés sur 52 sans poste (société 1) |
| **D6** | Passage des règles par rôle aux exigences par poste | a) proposition calculée, validée par la société ; b) automatique ; c) repartir de zéro | **a** | L1 | `doc_config` sur 40 rôles sur 49 |
| **D7** | Finalité, base légale et texte d'information du dossier d'intégration | a) préréglés par la société, modifiables par dossier ; b) saisis à chaque fois | **a** | L2 | 5 décisions juridiques à chaque dossier |
| **D8** | Comptes de démonstration | a) e-mails nominatifs (`alice.martin@…`) ; b) inchangés | **a** (données de recette) | — | `admin@leezr.test` = Alice |
| **D9** | Établissements multi-SIRET | a) hors périmètre ; b) à concevoir | **a** | FUT | Aucun support actuel |
| **D10** | Une expédition peut-elle être affectée à un salarié sans compte ? | a) non (compte requis) ; b) oui (affectation au salarié) | **a** à court terme ; b si besoin terrain avéré | L9/FUT | `assigned_to_user_id` |
| **D11** | Modules non livrés dans le catalogue | a) masqués ; b) « Bientôt disponible » | **a** (ADR-566 : pas de coquille vendable) | L7 | 3 modules sans page ni permission |
| **D12** | Champs personnalisés redondants de la fiche (« Intitulé de poste », « Type de contrat », « Statut », « Date d'embauche ») | a) dépubliés, valeurs conservées et proposées à la reprise ; b) conservés | **a** | L4 | 3 formulaires sur un onglet |
| **D13** | Où vivent les paramétrages (types de pièces, types d'absence, rôles) ? | a) Réglages uniquement, avec lien « Configurer » ; b) dans chaque page de travail | **a** | L7 | Paramétrage mêlé au travail |
| **D14** | Après définition du mot de passe d'un nouveau compte | a) connexion automatique ; b) retour à la connexion (actuel) | **a**, sauf exigence de sécurité contraire (2FA obligatoire) | L8 | Parcours 9 |
| **D15** | Quand choisir le rôle d'accès d'une nouvelle recrue ? | a) à la finalisation de l'embauche ; b) à la création (actuel) | **a** | L2 | Rôle RBAC demandé à l'ajout |
| **D16** | « Mon activité » | a) menu personnel uniquement ; b) aussi onglet du hub de gestion (actuel) | **a** | L6 | Onglet personnel dans le hub de gestion |
| **D17** | Responsables proposés | a) salariés actifs uniquement, propriétaire inclus s'il a une fiche ; b) actuel | **a** | L1 | Un salarié **sorti** proposé comme responsable (parcours 24) |
