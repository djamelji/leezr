# ADR-815.2 — 07. Expérience par profil

> **Colonne « Aujourd'hui »** : observée en recette navigateur, ADR-815 (9 profils de la société 1, clone isolé) et parcours ADR-815.2. Navigation calculée par `NavBuilder` (`adr815/evidence/nav.json`).
>
> **Colonne « Cible »** : choix de conception [C]. Les profils sont des **ensembles de permissions** ; aucun profil n'est codé en dur. La cible ne décrit que ce qu'un rôle d'accès typique ouvre.

## Synthèse

| Profil | Aujourd'hui : arrivée / menu | Problèmes observés | Cible : arrivée / menu |
|---|---|---|---|
| **Propriétaire** | `/dashboard` ; 12 entrées dont « Accueil personnel » en plus de « Accueil » | Deux accueils ; grille d'entreprise dans « Accueil personnel » ; rôles dans Équipe | `/home` (Moi si salarié, À traiter, Entreprise, Démarrage) ; Équipe · Organisation · Documents · Planning & temps · Paie · Activité · Réglages |
| **Administrateur** (ex. Alice « Dispatcher ») | `/dashboard` ; Accueil, Équipe, Documents, Expéditions, Réglages | Le rôle d'accès porte un nom de métier ; c'est le compte `admin@` utilisé comme compte personnel | `/home` ; sections selon permissions ; le rôle s'appelle par exemple « Administration » |
| **RH** | `/dashboard` ; Équipe, Planning & temps, Documents, Réglages | **Ne peut ouvrir aucune fiche** (404) malgré `contracts`, `compensation_*` et `sensitive_read` (R10) | `/home` (À traiter RH : embauches, pièces, contrats) ; Équipe (périmètre entreprise **explicite**), Organisation, Documents, Absences |
| **Manager** | `/dashboard` ; Équipe, Planning & temps, Réglages | 404 hors équipe (normal) ; menu « Réglages » sans contenu utile ; arrive sur des indicateurs d'entreprise | `/home` (À traiter : mon équipe) ; Équipe filtrée « Mon équipe » ; Planning ; Absences à valider |
| **Responsable d'exploitation** | `/dashboard` ; Équipe, Planning & temps, Réglages | Ne voit aucune fiche (R10) | `/home` (À traiter : planning, non pointés, expéditions) ; Planning ; Activité |
| **Chauffeur** | `/home` ; Accueil, Mes livraisons, Mon espace, Mon activité, Compte | Lit l'annuaire complet par l'API (R1) ; `theme.manage` | `/home` (pointage, prochain créneau, livraisons du jour) ; menu personnel seul ; vue mobile d'abord |
| **Salarié** | `/dashboard` (!) ; Accueil, **Suivi du temps** (écran de gestion), Mon espace, Mon activité | Agrégats d'entreprise (P0) ; écran de gestion du temps | `/home` (Moi) ; menu personnel seul |
| **Utilisateur limité** (consultation, sans fiche salarié) | `/dashboard` (rôle sans archétype) | Peut tomber sur des pages vides (« Aucun réglage accessible ») | `/home` (bloc Moi vide → message « rien à faire pour vous » + liens vers ce qui est autorisé) |

## Parcours type par profil (cible)

### Propriétaire (petite entreprise, 1 à 20 personnes)
1. Après l'inscription, l'accueil affiche **Démarrage** : profil de l'entreprise, premier poste, première embauche, premier module, premier document. C'est la checklist actuelle, reliée aux nouveaux objets.
2. **Embaucher** depuis l'accueil ou Équipe → assistant (voir `06` § Embaucher).
3. Le bloc « À traiter » regroupe tout ce qui attend une décision : pièces à valider, congés, anomalies, expéditions non assignées.
4. Réglages seulement pour configurer. Il n'y a jamais besoin d'y aller pour travailler.

### Administrateur (délégué du propriétaire)
- Même chose que le propriétaire, limité par ses permissions. Les Réglages › Accès lui montrent le **périmètre** de chaque rôle avant de l'attribuer. L'anti-escalade existante est conservée.

### RH
- « À traiter RH » : embauches en cours, pièces à valider, contrats à signer, expirations à 30 jours.
- Équipe avec le périmètre **entreprise** dès que le rôle l'accorde. C'est explicite dans l'éditeur ; aujourd'hui, cela exige `workforce.manage` sans le dire.
- Accès aux données sensibles (rémunération, NIR) gardé par `sensitive_read` et `compensation_*`, avec affichage masqué par défaut.

### Manager
- « Mon équipe » par défaut : présents, absents, anomalies, congés à valider.
- Planning de son équipe. Il ne voit jamais la paie ni les données sensibles.

### Responsable d'exploitation
- Planning et couverture, expéditions du jour, non-pointés. Équipe en lecture sur son périmètre.

### Chauffeur (mobile)
- Accueil = sa journée : pointer, livraisons dans l'ordre, prochain créneau, pièces à fournir (ex. permis expirant).
- Aucune donnée collective. L'annuaire, s'il est conservé (décision D3), se limite au nom, à la photo et au poste.

### Salarié (sédentaire)
- Accueil = Moi : pointage, planning, congés (demande en 1 dialogue), documents et bulletins, signatures.

### Utilisateur limité / consultation
- Accueil explicite ; aucun écran vide sans explication ; les liens visibles mènent uniquement à ce qui est autorisé.

## Données strictement personnelles (règle)

Le bloc Moi, Mon espace et Mon activité ne lisent **que** des endpoints `self` (`/profile/*`, `/workforce/me/*`, `/my-deliveries/*`), déjà existants et déjà scopés [P]. Les widgets d'entreprise n'y figurent jamais (correction du P0).
