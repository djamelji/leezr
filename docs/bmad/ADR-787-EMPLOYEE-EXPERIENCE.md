# ADR-787 — Employee Experience 2.0

> Base `1d204a96` · DEV uniquement · aucun push, aucune PROD, aucun VPS · Workforce, DMS, paie et Billing non reconstruits ni modifiés dans leurs calculs.
> Preuves : `docs/bmad/adr787/` (captures `before-*` / `after-*`) · tests `tests/Feature/Adr787EmployeeExperienceTest.php`, `tests/e2e/adr787-recette.spec.js`, `tests/e2e/adr787-capture.spec.js`.

## 1. Expérience actuelle de Bob (avant)

Bob (`bob@leezr.test`, rôle Driver, company 1, fiche salarié 3, contrat **actif**) :

| Sujet | Constat |
|---|---|
| Centre d'aide | groupe « Guide Entreprise » complet : démarrage, administration, membres, modules, facturation, support ; raccourcis « Ajouter un membre », « Comprendre ma facture », « Activer un module » ; aucun contenu pour un salarié ; « Contacter le support » mène à une page refusée. Les articles étaient servis à tout utilisateur connecté (filtre par audience seulement) |
| Heures | seul le jour en cours est affiché ; ni semaine, ni période, ni état de validation |
| Profil | nom, date de naissance, NIR, IBAN, type de contrat, permis… modifiables par lui-même malgré un contrat actif |
| Matricule | aucun numéro automatique (22 fiches DEV sans numéro) |
| Préférences | onglet ne contenant que thème et langue, déjà dans la barre du haut |
| Mon activité | cartes empilées d'icônes, « Mon contrat » deux fois plus haut que « Solde congés », états vides en cartes entières |
| Fiche d'un collègue | date et lieu de naissance, adresses privées, contacts d'urgence, champs RH et permis d'un collègue visibles avec `members.view` |

## 2. Centre d'aide filtré

- Règles d'accès optionnelles sur sujets et articles : `required_permissions` (une parmi), `required_modules` (toutes). Migration additive `2026_11_15_100000_add_access_rules_to_documentation`. Règles par défaut et sujet salarié dans `HelpCenterAccess` (source unique, appliquée par la migration et par `HelpCenterSeeder`), sans écraser une règle modifiée ni supprimer de contenu.
- Évaluation serveur : société **active** (`X-Company-Id`, appartenance vérifiée — l'en-tête seul ne suffit jamais), permissions effectives, modules actifs ; jamais le nom d'un rôle. Sans contexte vérifiable : aucun contenu restreint.
- Appliqué à la liste, au sujet, à l'article (404), à la recherche, aux articles liés et aux retours « utile ». Les champs de règles ne sortent jamais du serveur. Visiteurs anonymes et Platform : inchangés.
- Nouveau sujet « Mon espace salarié » (5 articles : compte et coordonnées, planning, pointage et heures, absences, documents et bulletins), chaque article conditionné au module qu'il décrit.
- Raccourcis et « Problèmes fréquents » : proposés seulement si l'article est servi à l'utilisateur ; raccourcis salarié ajoutés.
- « Contacter le support » : affiché seulement si le module support est actif **et** l'utilisateur a `support.view` ou `support.create` (décision serveur `can_contact_support`) ; visiteur : connexion ; sinon rien.
- Correction annexe : le centre d'aide (axios brut) n'envoyait pas la société active.

| Profil | Sujets visibles |
|---|---|
| Livreur (Bob) | Présentation, Fonctionnalités, Sécurité, FAQ (sans résiliation ni support), Mon espace salarié |
| Propriétaire | tout le guide entreprise + support |
| Membre d'une société sans Workforce | idem livreur, sans les articles pointage, planning, absences |
| Visiteur | contenus publics (inchangé) |

## 3. Compteurs d'heures

`GET /api/workforce/me/hours` (`EmployeeSelfHoursController`, `PersonalHoursReadModel`), propriété seule (aucun paramètre ne cible autrui, aucune permission de supervision), fiche salarié active requise (sinon 404 et aucun appel côté interface).

| Compteur | Source |
|---|---|
| Cette semaine | `TimeEntry` terminées (minutes nettes, pauses déduites par le moteur existant), par jour de travail, bornes = premier jour de semaine Company (`WeekStartResolver`), « aujourd'hui » dans le fuseau du marché |
| Période de paie / Ce mois | cycle de paie couvrant aujourd'hui s'il existe ; sinon **mois civil, libellé « Ce mois »** |
| À valider / Validées | lignes de feuilles de temps des périodes `submitted` / `approved`+`locked` |
| Pointage en cours | signalé à part, jamais compté |

Aucun second moteur ; corrections = mise à jour de l'entrée (pas de double comptage) ; texte explicite : les heures validées ne sont pas des heures payées. Écart prévu/réalisé **non affiché** : le planning publié ne donne pas encore une base fiable par salarié sur la période (défaut résiduel).

## 4. Politique de modification du profil

Constat de modèle : les champs du profil personnel (`company_user`) sont stockés **par utilisateur**, pas par société, comme le nom. Une valeur protégée est donc la même dans toutes les sociétés de la personne.

Règle (`ContractualIdentityLock`, contrôle serveur champ par champ, avant toute écriture) :
- Contrat **établi** = `active`, `suspended` ou `terminated`. `draft` et `cancelled` ne verrouillent rien.
- Restent modifiables : téléphone, adresse, adresse personnelle (rue, code postal, ville, pays), contacts d'urgence ; et le compte (e-mail de connexion, mot de passe, photo).
- Deviennent en lecture seule : nom, prénom, identité (naissance, nationalité, sexe), NIR, IBAN, données d'emploi, permis et champs métier.
- Portée : dès qu'une société de la personne détient un contrat établi (conséquence du stockage partagé ; un verrou limité à une société laisserait l'écran d'une autre société modifier la même valeur). Solution minimale proposée si un besoin multi-société apparaît : valeurs par société pour les champs de dossier RH.
- Renvoyer la valeur actuelle est accepté (le formulaire envoie tout) ; toute modification d'un champ verrouillé → 422, rien n'est écrit.
- Traçabilité : l'audit `user.profile_updated` liste les champs réellement modifiés (codes, jamais les valeurs sensibles).
- Les RH conservent leurs chemins (`members.manage`, fiche RH) ; aucun contrat ni document généré n'est modifié.
- Interface : bandeau explicatif, champs verrouillés en lecture seule avec « Figure à votre contrat — à demander à votre entreprise ».

## 5. Numérotation automatique des salariés

- Format existant majoritaire conservé : `EMP-000001` (préfixe configurable `workforce.employee_number.prefix`).
- Compteur par société dans une table additive `company_sequences` (migration `2026_11_21_000001`), ligne verrouillée `FOR UPDATE`, transaction rejouée en cas d'interblocage ; première utilisation : création de la ligne par `INSERT IGNORE` hors transaction, départ **après** le plus grand numéro existant du même format.
- Jamais `MAX()+1` ; jamais de réutilisation (le compteur ne fait que croître) ; un numéro saisi manuellement est conservé et sauté par la séquence ; l'index unique existant reste le garde-fou final.
- Génération uniquement à la création quand aucun numéro n'est fourni (`CreateEmployeeUseCase`, utilisé par tous les parcours). **Les 22 fiches DEV sans numéro ne sont pas renumérotées.**
- Modification : RH avec `workforce.manage` (route existante) ; unicité par société validée (422 au lieu d'une erreur serveur) ; historique `employee.number_changed` (avant / après) ; identité technique et références historiques inchangées ; aucun chemin pour le salarié.
- Concurrence réelle prouvée sur une base MySQL jetable (créée, migrée, supprimée) : 3 sociétés neuves × 25 processus simultanés → 75 numéros, tous distincts, sans trou ni erreur. Un premier essai avait révélé des interblocages à la toute première utilisation (création concurrente de la ligne de compteur) : corrigé avant validation.

## 6. Préférences redondantes

L'onglet « Préférences » ne contenait que le thème (clair/sombre/système) et la langue, déjà réglables depuis la barre du haut, sur les mêmes préférences enregistrées. Onglet et composant retirés ; `/account-settings/preferences` redirige vers le compte. Aucune préférence n'a perdu son interface (les notifications ont leur propre écran).

## 7. Mon activité — recomposition

- Bande du jour (inchangée), puis **Mes heures** (bande de 4 indicateurs, bornes en clair, note honnête).
- Deux colonnes équilibrées par le contenu, sans hauteur forcée : activité (Mon planning, Mes documents et bulletins réunis) | dossier (Solde congés en lignes avec l'action dans l'en-tête, Mon contrat en liste libellé/valeur).
- Surfaces neutres `AppSurface`, `AppStatStrip`, `AppStatusText` (ADR-782), plus d'avatars d'icône par carte, états vides sur une ligne, actions en liens d'en-tête. Variables du thème : clair et sombre. Mobile : une colonne, libellés courts.
- Captures : `before-company_workforce_me-{desktop,mobile}-{light,dark}.jpg` → `after-…`.

## 8. Proposition — portail candidat (non implémenté)

**Besoin** : la société définit ce qu'elle demande à une nouvelle recrue ; la recrue complète son dossier par un lien sécurisé, sans compte.

**Moteurs réutilisés (aucun système parallèle)**
| Besoin | Mécanisme existant |
|---|---|
| Personne sans compte | `Employee` sans `user_id` (ADR-772), fiche RH créée en statut « en recrutement » |
| Champs demandés | `FieldDefinitionCatalog` + `FieldActivation` ; exigences par **poste** (`JobRole`), service, JobDomain — jamais par rôle RBAC |
| Documents demandés | `DocumentTypeCatalog` + demandes documentaires (`document_requests`) + campagnes (ADR-763/M4) ; dépôts dans le DMS (`member_documents`, versions) |
| Éléments manquants | conformité « subject-driven » existante (ADR-763) |
| Passage en salarié | invitation existante (ADR-768/769) une fois le dossier validé |

**Architecture proposée** : un « dossier d'entrée » = un sujet documentaire `employee` + une liste figée d'exigences (instantané champs + types de documents) résolue depuis le poste au moment de l'envoi ; un jeton d'accès unique, haché en base, lié à ce dossier seulement ; pages publiques minimales qui n'appellent que des endpoints dédiés limités au dossier du jeton.

**Sécurité et conformité**
- Lien : jeton aléatoire long, stocké haché, à usage limité au dossier, **expiration** courte (ex. 14 jours) et révocable, renouvelable par la société ; limitation de débit ; aucun accès au reste de Leezr.
- Consentement : information RGPD explicite (finalité, durée, destinataires) acceptée et horodatée avant toute saisie ; minimisation (seuls les champs demandés).
- Données sensibles (NIR, IBAN, pièce d'identité) : mêmes catégories de confidentialité que le DMS (ADR-757/758), jamais réaffichées en clair au candidat après envoi.
- Validation : soumission → statut « à valider » ; un RH (`workforce.manage` / `documents.manage`) accepte ou demande une correction ; le contrat ne peut être établi qu'après validation (la règle du §4 s'applique ensuite).
- Fichiers : contrôles existants (types, tailles, quotas) ; antivirus à prévoir.
- Traçabilité : envoi, ouvertures, soumission, validation dans l'audit Company.
- Expiration et abandon : purge des données d'un dossier non abouti selon une durée configurable.

**Lots proposés** : P1 exigences par poste (configuration, sans portail) · P2 jeton + pages publiques + consentement · P3 validation RH et conversion en salarié · P4 relances et purge.

## 9. Permissions du rôle Driver

| Constat | Détail |
|---|---|
| `members.invite` | présent dans **7** rôles Driver (sociétés 1, 2, 3, 31, 32, 33, 34) ; seuls ceux de la société 1 ont des membres (12 : Bob et 11 comptes de test) ; **aucune invitation** n'a jamais été envoyée par un livreur → aucune responsabilité spécifique ne dépend de ce droit |
| Origine | préréglage antérieur à ADR-777 (`members.team_access`) ; ADR-777 a corrigé le préréglage sans modifier les rôles existants |
| Correction préparée | commande ciblée `roles:revoke-permission {role_key} {permission} [--company=…] [--apply]` : retire **une** permission des rôles d'**une** clé, simulation par défaut, audit `role.permission_revoked` à l'application ; jamais un réalignement de préréglage. Simulation exécutée en DEV (7 rôles listés), **rien appliqué** |
| Commande proposée pour validation | `php artisan roles:revoke-permission driver members.invite --apply` (toutes sociétés) ou `--company=1` |

**Annuaire, dossier RH, données sensibles** — distinction rendue explicite :
| Niveau | Contenu | Accès |
|---|---|---|
| Annuaire professionnel | nom, rôle, e-mail de connexion, poste, téléphone | `members.view` |
| Dossier RH | naissance, adresses privées, contacts d'urgence, emploi, contrat, permis | `members.manage` (gestion des membres) ou facette RH `workforce.view` dans son périmètre |
| Données sensibles | NIR, IBAN | en plus `members.sensitive_read` (inchangé) |

**Fuite corrigée** : `GET /company/members/{id}` et `/fields` renvoyaient tout le profil d'un collègue avec `members.view` seul, contrairement à ADR-754 (« `members.view` = facette accès, sans les données RH »). Désormais, sans `members.manage`, seuls `job_title` et `phone` sont renvoyés. La fiche unifiée suit automatiquement (elle lit ces endpoints).

## 10. Matrice RBAC (nouveaux points)

| Fonction | Livreur | Salarié | Manager sans fiche | RH (`members.manage`) | Propriétaire | Autre société |
|---|---|---|---|---|---|---|
| Aide administration (liste, URL, recherche) | non (404) | non | selon permissions | selon permissions | oui | non |
| Mes heures | soi | soi | 404 | soi si fiche | soi si fiche | 403 |
| Modifier nom / NIR / IBAN après contrat | 422 | 422 | — | oui (fiche membre) | oui | — |
| Modifier adresse personnelle | oui | oui | oui | oui | oui | — |
| Profil d'un collègue | annuaire | — (403) | — | complet | complet | 404 |
| Générer / modifier un matricule | non (403) | non | non | oui (`workforce.manage`) | oui | — |

## 11. Tests

| Niveau | Résultat |
|---|---|
| `Adr787EmployeeExperienceTest` (20) : aide livreur sans administration, refus par URL, recherche et retour ; propriétaire ; contexte Company vérifié ; articles conditionnés aux modules ; visiteur public ; semaine à cheval sur deux mois ; premier jour de semaine dimanche et fuseau ; période de paie et états de validation ; endpoint strictement personnel, sans fiche, société sans Workforce, non-membre ; profil sans contrat / brouillon / annulé ; contrat actif : nom et date de naissance refusés, adresse acceptée et tracée ; contrat terminé ; multi-société ; RH conserve l'édition ; annuaire sans données privées, RH complet ; matricules après existants, sans réutilisation, par société ; numéro manuel conservé et sauté ; unicité, historique, salarié refusé ; commande de révocation simulée puis appliquée | 20/20 |
| Concurrence MySQL réelle (base jetable) | 75/75 distincts |
| E2E `adr787-recette.spec.js` : aide livreur (contenus, raccourcis, support absent, 404 par URL), propriétaire, profil verrouillé et préférences redirigées, heures affichées, manager sans fiche sans appel | 5/5 |
| Suite PHPUnit complète (une fois après les dernières modifications) | 4368 réussis, 12 ignorés, 3 échecs historiques `ControllerSizeInvariantTest` (inchangés, contrôleurs touchés maintenus à leur taille) |
| Build final | propre |
| Budget de requêtes `CompanyMemberProfileTest` | 20 → 21 : +1 requête constante (contrôle annuaire / dossier RH), aucun N+1 |

Aucune écriture en DEV partagé par les tests : sonde et E2E en lecture seule ; concurrence sur base jetable supprimée. **Effet de bord corrigé** : la première série de captures en mode sombre avait enregistré la préférence de thème de Bob (`dark`) ; elle a été remise à `system` (valeur par défaut) et l'outil de capture n'écrit plus de préférence.

## 12. Captures

`docs/bmad/adr787/` : `before-company_workforce_me-*` / `after-company_workforce_me-*` (desktop, mobile, clair, sombre) ; `after-account-settings_account-*` (profil verrouillé) ; `after-help-center-*`, `after-help-center-bob.jpg`, `after-help-center-owner.jpg`, `after-profile-bob.jpg`.

## 13. Migrations locales (DEV)

`2026_11_15_100000_add_access_rules_to_documentation` et `2026_11_21_000001_create_company_sequences`, additives, exécutées sur la base DEV locale uniquement (aucun environnement distant).

## 14. Défauts résiduels

1. Écart prévu / réalisé non affiché (pas de base fiable par salarié sur la période).
2. Verrou du profil global à la personne (stockage partagé) ; valeurs par société à envisager si besoin.
3. Les 22 fiches DEV sans matricule restent sans numéro (attribution à décider explicitement).
4. Les 7 rôles Driver gardent `members.invite` tant que la commande n'est pas appliquée ; le préréglage terrain garde `documents.view` (tableau de conformité de l'entreprise).
5. L'éditeur Platform du centre d'aide ne permet pas encore de modifier les règles d'accès (règles par défaut en code).
6. Portail candidat : proposition seulement.
