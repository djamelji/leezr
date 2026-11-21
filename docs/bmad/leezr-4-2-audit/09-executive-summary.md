# Leezr 4.2 — 09 · Synthèse pour le propriétaire

## En une phrase

**Leezr fonctionne et tient ses promesses de sécurité entre sociétés ; ce qui manque, c'est surtout de la cohérence : expliquer pourquoi une fonction n'est pas disponible, empêcher les changements commerciaux accidentels, et ranger quelques écrans surchargés.**

## Ce qui a été fait

- Une copie isolée de la base a été créée pour l'audit. Rien n'a été modifié sur DEV ou en production, rien n'a été poussé.
- **20 profils** ont navigué réellement dans l'application, chaque onglet ayant été cliqué :
  - 5 profils Platform : super-administrateur, gouvernance, finance, support, opérateur de création ;
  - 13 profils Company, répartis sur 6 sociétés, 2 métiers, 2 plans et des rôles variés ;
  - 1 visiteur non connecté ;
  - 1 seconde vague ciblée.
- Au total : **923 affichages de page**, **103 pages sur 110** couvertes, captures à l'appui. Les 7 pages restantes sont listées avec leur raison, le plus souvent un manque de données de test.
- **5 parcours complets** avec de vrais clics :
  - créer une société depuis Platform ;
  - recevoir l'e-mail d'activation et se connecter ;
  - découvrir les modules ;
  - demander un congé ;
  - envoyer un ticket de support et le voir côté Platform.

  Quatre sont réussis. Un, l'invitation d'un collaborateur, est partiel : le robot n'a pas su remplir le formulaire, sans que l'application soit en cause.
- **20 tentatives d'accès interdites** : données d'une autre société, outils d'administration avec un compte simple. **19 refusées.** La seule acceptée est un chauffeur qui voit l'annuaire de sa propre société (voir plus bas).

## Les chiffres clés

| | |
|---|---|
| Erreurs techniques graves (pannes, erreurs JavaScript) | **0** |
| Problèmes d'affichage sur mobile (390 px) | **0** |
| Fuites de données entre sociétés | **0** |
| Défauts bloquants trouvés | **2** : l'historique de facturation est vide pour tous les clients ; le changement de plan peut se faire par accident depuis la liste des sociétés |
| Note d'ergonomie moyenne | **Platform 74/100 · Company 78/100** |

## Les 5 points qui comptent

1. **L'incident de sécurité n'est pas clos.** Trois actions vous reviennent, et aucune n'est encore prouvée :
   - changer le mot de passe SSH partagé ;
   - verrouiller les comptes de démonstration en production ;
   - changer le mot de passe du super-administrateur.

   C'est la priorité absolue.
2. **Un changement de plan peut se faire par erreur.** Dans la liste des sociétés, un simple menu déroulant change le plan d'un client tout de suite, sans confirmation. Un client annuel repasse alors en mensuel.
3. **L'historique de facturation de chaque société est vide.** Une erreur d'adresse d'une ligne en est la cause, et la correction est immédiate.
4. **Quand une fonction n'est pas disponible, l'utilisateur ne sait pas pourquoi.**
   - Module non activé : il est renvoyé à l'accueil sans explication.
   - Fonction hors de son offre : rien ne lui propose de passer à l'offre supérieure.
   - Profil sans certains droits : il voit des écrans à moitié vides.

   C'est la principale source de confusion, et aussi un manque à gagner.
5. **Le chiffre d'affaires mensuel (MRR) n'est pas le même partout** : 174 € dans le tableau de bord et la facturation, 261 € dans la liste des sociétés. Il faut une seule définition.

## Ce qui va bien

- L'accueil des sociétés est opérationnel : tâches à traiter, actions rapides, pointage.
- Les réglages sont bien rangés par sujet.
- Chaque profil ne voit que ce qui le concerne.
- La création d'une société depuis Platform et l'activation du compte fonctionnent de bout en bout.
- Les sociétés sont bien isolées les unes des autres, et les fichiers déposés sont contrôlés.

## Ce que je recommande

Ne pas refaire l'interface. **L'organisation actuelle est la bonne** : les menus découlent des modules, le plan fixe les droits commerciaux et le rôle fixe les autorisations. Il faut **appliquer partout trois règles** qui ne le sont qu'à moitié :

1. On ne montre que ce que l'utilisateur a le droit de faire.
2. Une fonction indisponible dit pourquoi, et comment l'obtenir.
3. Une décision commerciale (plan, résiliation, dérogation) passe toujours par un aperçu chiffré et une confirmation.

**Ordre proposé** (détail dans `08-roadmap.md`, 13 missions) :
1. Clôturer l'incident de sécurité.
2. Faire les correctifs immédiats : facturation, changement de plan.
3. Durcir la double authentification et la traçabilité.
4. Corriger les droits des rôles existants.
5. Appliquer les trois règles.
6. Ranger les menus Platform.
7. Préparer la fin de contrat d'un client : export de ses données et procédure de clôture. C'est indispensable avant un lancement commercial.

**Aucun chantier n'est lancé** avant votre validation de ces priorités.

## Documents

`01-inventory` (inventaire et matrice de couverture) · `02-platform-ux` · `03-company-ux` · `04-functional-gaps` · `05-technical-cleanup` · `06-security` · `07-target-experience` · `08-roadmap`. Les données brutes et les captures sont dans `data/`.
