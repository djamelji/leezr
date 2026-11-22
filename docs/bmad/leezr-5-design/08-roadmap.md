# Leezr 5.0 — 08 · Plan de transformation coordonné

## État au 2026-11-22

| Lot | État | Référence |
|---|---|---|
| **L0 · Fondations** | **livré en local** : bibliothèque unique `components/app/`, jetons `styles.scss`, profils par famille, laboratoire unique `/platform/design-lab` | ADR-855, [10](10-l0-l1-delivery.md) |
| **L1 · Dashboard Platform V35** | **livré en local** : connecté aux vraies données, permissions prouvées (5 profils), desktop, tablette et mobile, clair et sombre | ADR-855, [10](10-l0-l1-delivery.md) |
| L2 · Santé historisée | à faire (le panneau affiche l'état sans courbe inventée) | — |
| **L4 · Widgets premium** | **livré en local** : bibliothèque unifiée (tuile KPI, liste, tendance sur les composants L0), bornes par famille avec refus expliqué, états erreur/vide/chargement, « Disposition recommandée » Company, filtrage de la disposition Company à l'écriture ; restant : [12 §6](12-l4-delivery.md) | ADR-856, [11](11-widget-catalog.md), [12](12-l4-delivery.md) |
| **L3 · Accueil Company V22** | **livré en local** : en-tête de situation réelle, À traiter, actions en tuiles, composition par modules actifs (Logistique en tête), accueil chauffeur et salarié, mise en service sur la checklist existante ; restant : [13 §9](13-l3-delivery.md) | ADR-857, [13](13-l3-delivery.md) |
| **L5 · Personnes** | **livré en local** : page Personnes V22 (synthèse réelle, filtres avec compteurs, dossier, prochaine étape, aperçu rapide, mobile), « Outils → Champs » retiré au profit d'une interface par responsabilité, audit et migration réversible des règles de champs ; restant : [14 §7](14-l5-delivery.md) | ADR-858, [14](14-l5-delivery.md) |
| **L6 · Dossiers et interfaces redondantes** | **livré en local** : complétude des dossiers RH pour tous les collaborateurs (avec ou sans compte, poste, pièces, étape), règles par rôle analysées sans conversion, Modules (désactiver dans « ⋯ », « Configuré » vert), deux réglages « tout le monde » expliqués, alias vérifiés ; restant : [15 §7](15-l6-delivery.md) | ADR-859, [15](15-l6-delivery.md) |
| **L7 · Harmonisation** | **terminé (local)** : L7.1–L7.5 ([16](16-l7-delivery.md)) puis L7.6–L7.11 ([17](17-l7-completion.md)) — 9 familles restantes traitées, Flotte selon l'activité réelle (réglage par société, appliqué sur le clone seulement, GO requis ailleurs), 0 bouton-icône sans nom, titres anciens des familles L7 convertis, recette stabilisée (429 de démarrage corrigés) | ADR-860, ADR-861, [16](16-l7-delivery.md), [17](17-l7-completion.md) |
| L8 | inchangé | — |

> **Rien de ceci n'est lancé.** Chaque lot démarre sur une validation explicite (« GO L… »).
>
> Ordre imposé par les dépendances : **fondations d'abord**. Sans jetons, gabarits et profils de taille communs, chaque écran refait serait une nouvelle variante.
>
> Effort : S ≈ 1–2 jours · M ≈ 3–5 jours · L ≈ 1–2 semaines (agent, tests et recette compris).

| Ordre | Lot | Contenu | Valeur métier | Impact UX | Dépendances | Effort | Risques |
|---|---|---|---|---|---|---|---|
| 1 | **L0 · Fondations visuelles communes** | <ul><li>Fusionner les 3 laboratoires existants et le `design-lab` en **un seul** laboratoire de référence.</li><li>Promouvoir les composants `lab/` en presets Leezr (`resources/ui/presets/`, `docs/bmad/06-ui-policy.md`).</li><li>Jetons : rayons, espacements, densité, mouvement, `.min-w-0`.</li><li>Extraire `AppSegmented`.</li><li>Règle des actions (1 / 1 / ⋯) et règle de titre de page.</li><li>Libellés des boutons-icônes (697).</li></ul> | identité unique Platform et Company ; base de tous les lots | indirect, puis fort | aucune | M | dériver en refonte générale : garder le périmètre à des **outils**, aucune page métier |
| 2 | **L1 · Dashboard Platform V35** | <ul><li>Héros cockpit, 3 KPI, panneau santé en direct.</li><li>Revenus et encaissements, « À traiter » trié par impact.</li><li>Parcours d'inscription, essais, adoption des modules, activité, offres.</li><li>Masquage strict des données financières selon la permission.</li></ul> | pilotage du SaaS en 5 secondes | **très fort** (première impression du propriétaire) | L0 ; widgets existants | M | fuite d'un chiffre financier à un profil sans droit : un test par permission et par widget |
| 3 | **L2 · Santé Platform historisée** | échantillon par minute (24 h) des mesures de `SystemHealthService`, plus la latence de requête ; courbes du panneau | détecter une dégradation avant l'incident | moyen | L1 | S | volume (purge à 24 h) ; aucune donnée personnelle |
| 4 | **L3 · Dashboard Company V22** | <ul><li>Héros espace de travail (phrase du jour, anneau, présence).</li><li>KPI selon le métier et le rôle, agenda du jour, actions contextualisées, état des livraisons, heures pointées et planifiées, équipe, fil d'activité.</li><li>Démarrage guidé pour les nouvelles sociétés.</li></ul> | adoption quotidienne ; chaque rôle voit sa journée | **très fort** | L0 ; widgets existants ; nouveaux : agenda, fil, actions | L | permissions par rôle (chauffeur en moindre privilège) ; ne jamais afficher un widget sans donnée |
| 5 | **L4 · Widgets responsives** | <ul><li>Profils de taille **par famille** avec la même mesure (ADR-748) ; tailles refusées à la poignée.</li><li>Gabarit `LzWidget` sur les 47 widgets ; hauteur de graphique fixe par mode.</li><li>« Restaurer la disposition par défaut ».</li></ul> | des dashboards toujours lisibles, quelle que soit la disposition | fort | L0 | L | régression visuelle sur des dispositions sauvegardées : recette visuelle sur le laboratoire, matrice de tailles |
| 6 | **L5 · Page Personnes** | segments à compteurs, ligne utile (complétude du poste, prochaine action), consultation rapide en tiroir, vue cartes | le travail RH devient une file d'actions | fort | L0 ; exigences par poste (existantes) | M | performance de la complétude par personne : calcul côté serveur, déjà présent pour les exigences |
| 7 | **L6 · Nettoyage des interfaces obsolètes** | <ul><li>Déprécier le tiroir « Outils › Champs ».</li><li>Migrer `required_override` (champs et documents) en exigences « tous les postes », avec repli pendant une version.</li><li>Fusionner les doublons (05 §2) ; déplacer les actions mal placées (05 §4).</li><li>Mesurer l'usage des alias avant toute dépréciation.</li></ul> | une seule règle compréhensible, moins de confusion | moyen | L5 | M | **migration de données** : simulation sur clone, empreintes avant et après, réversible, sociétés historiques en lecture d'abord |
| 8 | **L7 · Harmonisation des autres pages** | <ul><li>Facturation Platform en 3 groupes, Paramètres en 4, Messagerie en boîte de réception.</li><li>Entreprises et fiche entreprise ; Modules, Planning et Documents Company.</li><li>Titres par onglet ; états vides actionnables ; vue organigramme.</li></ul> | cohérence globale, moins de clics | fort | L0, L4 | L | adresses et onglets existants : garder les noms de route (ADR-475), alias si nécessaire |
| 9 | **L8 · Évolutions fonctionnelles** | <ul><li>Module **scheduling** (2ᵉ vertical, ADR-563/564).</li><li>Tournées (ordre des arrêts, heure d'arrivée estimée).</li><li>Rapport mensuel Platform.</li></ul> | nouveaux usages, 2ᵉ vertical | fort | doctrine ADR-561/565 (largeur avant profondeur) | L par module | à arbitrer avec la feuille de route produit T0→T4 ; hors design |

**Recommandation.** Commencer par **L0 puis L1** : la valeur est visible immédiatement et le risque est faible, puisqu'aucune donnée n'est modifiée. Le prototype cockpit montre déjà le résultat attendu.

Ensuite, L4 avant L3, pour que l'accueil Company s'appuie sur des widgets déjà robustes. L5 et L6 vont ensemble, et L7 se fait au fil de l'eau.

**Critères de fin de chaque lot.**
- Captures avant et après, desktop, mobile et sombre.
- Recette navigateur par profil.
- 0 violation d'accessibilité grave sur les pages touchées.
- Tests de permission des widgets.
- Suite PHPUnit et build propres.
- ADR.
