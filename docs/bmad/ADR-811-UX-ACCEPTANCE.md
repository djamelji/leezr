# ADR-811 — Recette UX globale (navigateur)

- **Date** : 2026-11-16, DEV uniquement, base `e750f75c`.
- **Méthode** : recette au navigateur (Playwright, Chrome), pas un inventaire de code.
  - Un parcours par profil relève tous les liens du menu, puis visite chaque entrée et une liste d'URL directes.
  - Sur chaque écran, il note : erreurs console, réponses API ≥ 400, clés i18n brutes, débordement horizontal, page vide, capture.
  - Les parcours métier sont faits **par l'écran**, sur une société fictive.
- **Écritures** : uniquement sur la société fictive **101** (« E2E Recette 8091 »), créée par la vraie inscription du lot ADR-809.1. Les sociétés réelles n'ont pas été touchées.
- **Preuves** : `docs/bmad/adr811/` (sélection de captures et e-mail d'invitation, jeton masqué).

## 1. Profils

| Profil | Compte | Rôle |
|---|---|---|
| Platform super_admin | `admin@leezr.com` (DEV) | super_admin |
| Platform limité | `support-only@leezr.com` (DEV, lecture seule) | support_only |
| Company Owner | propriétaire inscrit en ADR-809.1 | Propriétaire |
| Administrateur non propriétaire | invité par l'écran | Operations Manager (administratif) |
| Manager | invité par l'écran | Manager |
| Salarié | invité par l'écran | Dispatcher |
| Chauffeur | invité par l'écran | Driver |
| Employee sans compte | créé par l'écran (« Non, pas pour le moment ») | — |

Les quatre invités ont reçu leur e-mail (Mailpit) et défini leur mot de passe depuis le lien.

Variantes testées :
- mobile 390 px en mode sombre (propriétaire) ;
- mobile en anglais (chauffeur) ;
- desktop en anglais (propriétaire).

## 2. Parcours testés et résultat

| Parcours | Résultat |
|---|---|
| **A. Inscription** | Choix du métier Logistique → offre → entreprise (SIRET) → compte → 201 → accueil en 21,5 s, sans 429 (ADR-809.1). L'accueil présente la checklist de démarrage (4/5) et des actions utiles. |
| **B. Platform** | Tableau de bord, messagerie, sociétés (liste et fiche 101), support, finance, catalogue (modules, domaines d'activité, presets, plans), accès (utilisateurs et rôles), modèles de contrat, réglages, opérations : **tous chargés**. Le profil limité est ramené vers son seul espace (Support) sur toutes les URL directes. |
| **C. Company** | Tableau de bord, Équipe, création de personne (avec et sans compte), invitation, fiche, onglet Rôles, Modules, activation, assistant de configuration (accès, temps de travail), Réglages, Abonnement, Audit, Support, Notifications. |
| **D. Workforce** | Pour Charles : contrat (brouillon → activé) → créneau → publication → visible dans « Mon planning ». Pointage « Commencer la journée » → en cours. Soldes de congés → demande → validation par le propriétaire. Permis déposé dans Mon espace → « Transmis » → approuvé depuis « À traiter ». Signatures, modèles de contrat et réglages RH s'ouvrent. |
| **E. Logistique** | Expédition créée → planifiée → assignée à Charles (liste « Assignation ») → visible dans « Mes livraisons » → en transit → livrée. |
| **F. Navigation** | Menus conformes aux droits de chaque profil. Les URL directes interdites mènent à la page « Accès refusé » (redirection) ou à l'accueil. Aucun débordement horizontal en mobile. Le mode sombre et l'anglais s'appliquent. |

**Par conception, non signalés comme anomalies** :
- Modules Workforce absents tant qu'ils ne sont pas activés : écrans redirigés vers l'accueil.
- Paie non activée et non utilisée (règle : aucun calcul de paie).
- Limite de 5 membres de l'offre Starter.
- Droits d'un module : les autres rôles n'en reçoivent qu'après l'assistant du module.
- Factures annexes sans numéro (`parent_invoice_id`).
- Rôle Propriétaire protégé contre l'attribution par un non-propriétaire (`MembershipSecurityPolicy` 3 bis).
- « Analyse en cours… » sur une pièce déposée : la file DEV n'a pas de worker.

**Écartés après vérification** (faux positifs de l'outil) :
- pages en cours de chargement au moment de la capture : fiche d'un collaborateur, assistant du module ;
- « Désassigner » quand rien n'est sélectionné (le libellé passe à « Assigner » dès qu'un membre est choisi) ;
- livraison : réponse lente, mais l'état final « livrée » est correct ;
- écrans de démarrage lents pendant que deux recettes tournaient en parallèle.

## 3. Anomalies

**P0** (sécurité, fuite, facturation, action destructive) : **aucune constatée.** Points vérifiés :
- aucune donnée d'une autre personne n'est servie sans droit (réponse 404 ou 403 du serveur) ;
- la Platform limitée est cantonnée à son espace ;
- l'anti-escalade de rôle est active ;
- les factures de modules correspondent aux prix affichés (29, 19 et 9 € HT, TVA 20 %).

**P1** (parcours bloqué) : **aucun blocage définitif.** Les deux impasses relevées (D3, D4) se lèvent en activant le contrat ; elles sont classées P2 parce que rien ne l'explique.

| ID | Gravité | URL / profil | Action | Attendu | Réel | Cause prouvée |
|---|---|---|---|---|---|---|
| C1 | **P2** | e-mail reçu par un nouvel invité | ajout d'une personne avec invitation | e-mail d'invitation nommant l'entreprise | e-mail Laravel générique en anglais : « Reset Password Notification… we received a password reset request ». Aucune mention de l'entreprise ni de l'invitation (preuve : `adr811/invitation-email-before.txt`) | `MembershipInvitation::sendNow` envoie `ResetPassword` pour un nouveau compte |
| C2 | **P2** | `/reset-password` (invité) | définir son mot de passe | règles en français, texte adapté à une première connexion | règles en anglais (« At least 8 characters »…) et sous-titre « doit être différent des mots de passe utilisés précédemment » | `usePasswordStrength.js` contient des libellés en dur |
| A2 | **P2** | `/company/modules` (propriétaire, FR et EN) | lire le prix de Ressources humaines et de la Paie | « 5 salariés inclus, puis 3 €/salarié » | « 5 companyModules.metric_employees inclus, puis 3 €/companyModules.metric_employees » | clé `companyModules.metric_employees` absente de fr/en |
| C3 | **P2** | `/company/modules/workforce` (propriétaire) | « Appliquer la proposition « Logistique — rôles initiaux » » | les accès proposés sont cochés | rien ne change, aucun retour | le preset RBAC Logistique ne contient aucun droit Ressources humaines ; `ModuleSetupService::suggestion` annonce une proposition vide comme disponible |
| D3 | **P2** | `/company/workforce/planning` (propriétaire) | créer un créneau pour une personne ajoutée par « Ajouter une personne » | créneau créé, ou explication | toast en anglais « Employee must have an active contract. », sans indiquer quoi faire | règle légitime (I4) ; le message serveur brut est affiché tel quel |
| D4 | **P2** | `/company/workforce/me` (chauffeur sans contrat actif) | « Demander un congé » | demande possible, ou explication | liste « Type » vide (« Aucune donnée disponible »), envoi impossible, sans explication | les types proposés viennent des soldes, qui n'existent qu'avec un contrat actif |
| F1 | **P2** | `/company/people/employee-{id}` par URL directe (admin, manager, salarié, chauffeur) | ouvrir la fiche d'une personne hors de son périmètre | « fiche introuvable ou accès refusé » | « Une erreur est survenue. Veuillez réessayer. » avec un bouton Réessayer inutile | le serveur répond 404 à juste titre ; `person.store` ne conserve pas le code HTTP |
| C4 | **P2 — décision produit** | Équipe, invitation, assistant | lire les rôles d'une société créée sans preset | noms dans la langue de la société | « Propriétaire » en français ; « Dispatcher », « Driver », « Manager », « Operations Manager » en anglais | noms par défaut de `JobdomainRegistry` (logistique). Les presets publiés utilisent des noms français (« Chauffeur-livreur »). Ce sont des données modifiables par chaque société. |
| P3-1 | P3 | `/company/audit` | lire l'action | libellé | code technique (`member.invitation_delivered`) | affichage voulu dans une balise `<code>` |
| P3-2 | P3 | `/platform/dashboard/overview` | ouvrir puis quitter | — | erreur console ApexCharts `forceXAxisUpdate` | graphique démonté avant sa mise à jour |
| P3-3 | P3 | Mon espace → Mes documents | lire les formats acceptés | « PDF, JPG, JPEG, PNG » (comme sur la fiche) | « Types de fichiers acceptés » | libellé générique |
| P3-4 | P3 | Mon activité (chauffeur) | bloc « Mes documents et bulletins » | — | « Aucun document pour le moment » alors que 3 pièces sont à fournir | le bloc ne liste que les documents reçus |

**Reproduction commune** :
1. Inscription Logistique Starter.
2. Activer Ressources humaines, Planning, Congés, Documents, Contrats et Signatures.
3. Ajouter quatre personnes avec invitation, et une sans compte.
4. Ouvrir les écrans cités avec le profil indiqué.

## 4. Suite

Corrections P2 (sauf C4, qui demande une décision produit) : ADR-812.
