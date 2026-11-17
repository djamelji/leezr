# Leezr Company 2.0 — rapport final (ADR-819)

> DEV uniquement, aucun push. Base : `d9ae6d2b` (ADR-818). Décision : `docs/bmad/04-decisions.md` › ADR-819.
> Preuves : `docs/bmad/company-2-0/` (recette `acceptance.json`, revue des pages `page-sweep.json`, captures `shots/`).

## 1. Commits

| Commit | Contenu |
|---|---|
| `7afa9a9f` | Documents intelligents : une exigence, plusieurs sources compatibles (contrat signé dans Workforce) |
| `bbcbe98d` | Phase 1 : catalogues de la société, socle métier minimal, onglets du poste, application explicite aux personnes en place |
| `32a9f610` | Phase 3 : dossier d'embauche unique et collaboratif (états, files, historique, participants, révision 409) |
| `b3e13c70` | Phase 4 : module Workforce Parrainage |
| `6c1b7f12` | Correctifs de recette + suppression du code mort |
| `1345e6c4` | Libellés d'audit FR/EN/AR, sélecteur strict de la spec E2E d'intégration |
| *(ce commit)* | ADR-819, rapport final, preuves |

Ordre réel des phases : la phase 2 (documents) a été livrée en premier, car elle ne dépendait de rien. Les autres phases ont suivi l'ordre du plan.

## 2. Architecture métier finale

```
JobDomain (socle minimal)  ──►  Catalogues de la société  ◄──  Modules actifs (contributions)
   champs/documents actifs        champs + types de documents       champs, documents, exigences
   + « recommandés »              origine · état · usage            proposés, jamais imposés
                                          │
                                          ▼
                              Poste (Organisation › Postes)
                     Champs · Documents (obligatoire/facultatif, moment)
                                          │  calcul effectif : rôle OU poste (par société, réversible)
                                          ▼
              Dossier individuel  ◄── Embauche (dossier unique) ◄── Parrainage (candidature consentie)
              pièces + preuves module (contrat signé)                   │
                                          ▲─────────────────────────────┘ conversion sans ressaisie
Rôle RBAC = accès au logiciel uniquement (jamais les exigences pour une société basculée)
```

Les concepts restent distincts :
- **type de document** : `DocumentType` ;
- **exigence** : `Requirement`, registre L1 ;
- **demande** : `DocumentRequest` ;
- **document fourni** : `MemberDocument` ;
- **preuve** : `DocumentProofRegistry`.

Aucun nouveau moteur DMS, Requirements ou RBAC.

## 3. Contribution du JobDomain (Logistique)

- Une entrée de preset peut être `recommended` : elle est proposée au catalogue, jamais activée ni exigée.
- **Activés à la création** : 8 champs personne au lieu de 20 :
  - poste occupé ;
  - date de naissance ;
  - téléphone ;
  - adresse ;
  - contact d'urgence (nom et téléphone) ;
  - date d'entrée ;
  - type de contrat.
- **Recommandés** :
  - champs : nationalité, statut, NIR, IBAN, n°/catégorie/expiration du permis, ADR, type de véhicule, zone, horaires, mode de travail ;
  - document : certificat médical.
- **Documents activés** : carte d'identité (exigée de tous), permis de conduire, Kbis, attestation d'assurance (pièces de l'entreprise).
- Logistique autorise désormais les champs personnalisés.
- **Où la règle `recommended` est respectée** :
  - affectation classique (`JobdomainGate`) ;
  - initialisation versionnée (`VersionedCompanyInitializer`) ;
  - reprise historique (`LegacyJobdomainConfig`, niveau `recommended`) ;
  - commande `jobdomain:sync-logistics-fields`.
- **Sociétés existantes** : inchangées, l'activation n'est lue qu'à l'affectation. Les versions de presets publiées sont conservées et rien n'est réappliqué.
- **Migrations ciblées** : `2026_12_09_000001`, `…000002`. Pas de synchronisation complète du registre.

## 4. Contributions des modules

- **Manifestes existants (ADR-794)** :
  - Workforce : date d'entrée, type de contrat, IBAN, pièce d'identité, RIB ;
  - Paie : ses éléments propres.
- **Nouvelle contribution « preuve »** :
  - Workforce déclare `SignedContractProof` pour `work_contract` ;
  - c'est la première source de satisfaction ; le registre en accepte d'autres sans changer le Core.
- Le catalogue affiche l'origine « Module » avec l'état du module (contour pointillé si inactif). Une contribution référence une définition canonique : jamais de doublon, jamais d'obligation implicite.
- **Désactivation d'un module** :
  - inchangée (aperçu d'impact ADR-805, données conservées) ;
  - une preuve module n'est plus reconnue si `workforce_contract` est inactif, mais le PDF signé reste au coffre.

## 5. Catalogues de la société — `Réglages › Catalogues`

- **Onglets** : *Champs* et *Types de documents*.
- **Outils** : recherche, filtres par origine et par état, et un menu par ligne : *Proposer dans les formulaires* / *Ne plus proposer*, *Exiger pour un poste*.
- **Création** :
  - champ : texte, nombre, date, oui/non, liste — seuls les types pris en charge par le stockage ;
  - type de document : formats, taille, expiration.
  - Rien de ce qui est créé n'est obligatoire tant qu'on ne l'exige pas pour un poste.
- **Colonne « Exigé pour »** : les postes qui exigent l'élément (lien vers l'onglet du poste) et la règle historique « tout le monde ».
- **Droits** : lecture si `settings.manage`, `documents.configure` ou `workforce.manage`. Les écritures gardent leurs droits d'origine.

## 6. Configuration des postes

- **Onglets** : Informations · Champs · Documents · Personnes · Historique et reprise. La reprise ADR-817 est conservée.
- **Bandeau du calcul effectif** :
  - « par poste » ;
  - ou « encore par rôle d'accès : configuration préparée » ; aucune conformité « par poste » n'est affichée tant que le calcul reste par rôle.
- **Moment de collecte** par exigence (à l'embauche, pendant l'intégration, en continu). Un changement de moment crée une nouvelle version : l'historique est conservé.
- **Onglet Personnes** :
  - état du dossier par personne (complet, N pièces à demander, N demandées) ;
  - bouton *Demander les pièces manquantes* : aperçu personne par personne, puis confirmation ;
  - sont ignorés : les embauches en cours, les personnes sorties, ce qui est détenu, prouvé ou déjà demandé ;
  - refusé (422) tant que la société calcule par rôle.

## 7. Transition des exigences historiques

Réutilisation d'ADR-817/818 :
- comparateur de parité ;
- `PositionRequirementProposal` ;
- bascule par société, prévisualisée personne par personne et réversible ;
- demandes historiques intactes.

**Ajouts ADR-819** :
- affichage honnête du calcul effectif dans chaque poste ;
- application explicite aux personnes en place ;
- moment de collecte.

**Aucune société n'a été basculée.** Décision de chaque société.

## 8. Documents intelligents

- **Règle** : un contrat signé dans Workforce satisfait l'exigence « Contrat de travail », comme un contrat téléversé. Conditions :
  - même société et bonne personne ;
  - contrat COURANT ayant pris effet (actif ou suspendu ; jamais un brouillon ni un brouillon abandonné) ;
  - DERNIER document généré pour ce contrat (une régénération remplace l'ancien) ;
  - statut **signé** : envoyé en signature ≠ signé ;
  - module `workforce_contract` actif.
- **Aucune copie** : la preuve référence `generated_document:{id}`.
- **Où la preuve est consultée** (seulement si aucun dépôt ne satisfait déjà l'exigence) :
  - conformité ;
  - dossier du collaborateur ;
  - dossier de sujet ;
  - création des demandes : jamais redemandé.
- **Lecteur non autorisé** : il ne voit ni la pièce ni la source de la preuve.
- **À la signature** : la demande « contrat de travail » encore ouverte SANS pièce est clôturée avec une note tracée. Une demande soumise reste à son vérificateur.
- **Affichage** : le dossier montre « Contrat signé dans Leezr » ; le catalogue indique que le type peut être satisfait ainsi.

## 9. Embauche collaborative

- **Dossier unique** (`/company/workforce/hiring/{id}`) :
  - identité, poste, date d'arrivée ;
  - progression ;
  - état de chaque champ et document ;
  - ce qui manque pour finaliser ;
  - actions selon l'étape : compléter sur place, envoyer ou relancer le lien, vérifier, demander un complément, finaliser, abandonner ;
  - participants (gestionnaires, candidat) et nombre de relances ;
  - historique (journal d'audit).
- **Espace Embauches** : files calculées par le serveur — à lancer, en attente du candidat, à vérifier, à finaliser, terminés, abandonnés.
- **Concurrence** : un jeton de révision est envoyé par la page. Une écriture RH (révision, complément, validation, finalisation, abandon) est refusée en 409 si le dossier a changé entre-temps. La page propose alors de recharger, sans rien écraser. Sans jeton, le comportement historique est inchangé.
- **Parcours prouvés** :
  - commencé sur place → poursuivi à distance → repris par un autre gestionnaire → finalisé ;
  - un dossier, une fiche préparatoire, aucun compte.

## 10. Workforce Parrainage (module `workforce_referral`)

| Acteur | Ce qu'il fait | Ce qu'il voit |
|---|---|---|
| Salarié (`workforce_referral.refer`, fiche salarié exigée) | *Mon espace › Parrainage* : postes ouverts, recommander un proche, partager un lien, retirer | statut grossier (lien partagé, en attente du proche, reçu par l'entreprise, clôturé, expiré) et le nom qu'IL a saisi |
| Proche (sans compte) | page publique mobile `/referral#t=…` : opportunité, consentement explicite, candidature, complément si demandé | rien d'autre ; lien à usage unique, expirable |
| RH (`workforce_referral.review`) | *Équipe › Parrainages* : candidatures consenties, doublons (salarié, embauche en cours, autre candidature), retenir, décliner, demander un complément, ouvrir l'embauche | rien avant consentement (un nombre seulement) |
| Gestion (`workforce_referral.manage`) | modes (recommander, partager, les deux), postes ouverts, notification, récompense (information), validité des liens, conservation | — |

- **Conversion** : moteur d'embauche existant, pré-rempli avec les éléments EN VIGUEUR du poste. Pas de compte. Pas d'accès du parrain au dossier RH.
- **Abus** :
  - limite de 10 parrainages par jour ;
  - limitation de débit des routes publiques ;
  - pot de miel ;
  - jetons hachés de 64 hexadécimaux ;
  - même réponse 404 pour un lien invalide, expiré ou utilisé ;
  - module vérifié à chaque appel public.
- **Rétention** : `referrals:purge` (planifiée, instrumentée) anonymise les parrainages clos après la durée de la société (30 à 730 jours) et les liens jamais utilisés.
- **Commercial** : module déclaré `available` mais SANS tarif. Il n'est donc ni vendable ni actif tant que Platform ne le tarifie pas ou ne l'inclut pas. Aucun changement de prix. Sur le clone, un tarif d'essai a été posé pour la recette.

## 11. Pages refondues ou créées

| Page | Nature |
|---|---|
| Réglages › Catalogues | **nouvelle** |
| Organisation › Postes › [poste] | refondue (onglets Champs / Documents, calcul effectif, personnes et application) |
| Équipe › Embauches | files de suivi, lien vers le dossier, dates localisées |
| Équipe › Embauches › [dossier] | **nouvelle** (dossier unique) |
| Équipe › Parrainages (+ Réglages) | **nouvelle** |
| Mon espace › Parrainage | **nouvelle** |
| `/referral` (public, mobile) | **nouvelle** |
| Dossier d'un collaborateur › Documents | mention de la preuve module |
| Journal d'audit | libellés des nouveaux événements |

**Revue des pages existantes** :
- **Périmètre** : toutes les pages atteignables du menu, par profil, sur ordinateur et mobile (390 px) :
  - propriétaire : 30 pages ;
  - administrateur des opérations : 19 ;
  - responsable : 19 ;
  - salarié (dispatcher) : 18 ;
  - chauffeur : 6.
- **Résultat** :
  - aucune erreur API, aucun débordement horizontal, aucune page vide ;
  - un défaut trouvé et corrigé : les actions d'audit brutes ;
  - les deux refus du salarié sont attendus (pages RH visitées hors menu).
- **Thème sombre** : vérifié sur les nouvelles pages (`shots/dark-*`).

**Limite honnête** : les écrans existants que la revue a trouvés conformes ne sont pas redessinés. La directive interdit de refaire un écran conforme ; Planning, Temps, Congés, Paie et Facturation sont fonctionnels mais n'ont pas reçu de refonte visuelle supplémentaire dans ce lot.

## 12. Pages et composants supprimés ou fusionnés

- **11 composants Company sans aucun consommateur** (vérifiés dans le code, les tests et la documentation) :
  - `DocumentStatusChip`, `DocumentLifecycleChip` : remplacés par `DocumentStateChip` (ADR-778) ;
  - `CompanyDocumentsSection` ;
  - `_WorkforceModuleCard`, `_WorkforceActionCard` ;
  - `_WorkflowCreateDrawer` ;
  - `_CompanyProfileDocuments` ;
  - `_PersonCreateEmployeeDialog` ;
  - `_DocumentsOverview` ;
  - `_BillingEmpty` ;
  - `_PayslipActionCards`.
- **Clés i18n mortes** : `hiring.stage.*`, deux filtres d'Embauches, `positions.tabs.requirements`, `positions.requirementsStatus`.
- **Alias** : l'onglet `?tab=requirements` du poste redirige vers « Documents ».
- **Conservés** : composants Vuexy de stock et pages Platform (hors périmètre).
- **Aucune route publique supprimée.**

## 13. Captures représentatives (`docs/bmad/company-2-0/shots/`)

- **Catalogues** : `01-catalog-fields`, `02-catalog-documents`.
- **Poste** : `03-position-documents`, `04-position-people`.
- **Embauche** : `05-hiring-file`, `06-hiring-index`, `dark-_company_workforce_hiring_10`.
- **Parrainage** :
  - réglages et salarié : `07-referral-settings`, `08-my-referrals-recommend`, `09-my-referrals-mobile` ;
  - candidat (mobile) : `10-candidate-done-mobile` ;
  - RH : `11-referral-review`, `12-referral-converted-file`.
- **Mobile et sombre** : `sweep-owner-m-_company_catalogs_fields`, `dark-_company_catalogs_fields`.

## 14. Tests

- **Nouveaux tests** :
  - `Adr819DocumentProofTest` : 4 ;
  - `Adr819CatalogsPositionsTest` : 4 ;
  - `Adr819CollaborativeHiringTest` : 2 ;
  - `Adr819ReferralTest` : 4.
- **Tests adaptés à l'intention métier** :
  - `JobdomainFieldPresetTest`, `LogisticsFieldsIntegrationTest` : socle minimal, la société adopte les champs recommandés ;
  - `Ux0IntentNavigationTest` : entrées Parrainage ;
  - listes d'exception revues : `CompanyModuleRoutesAreGatedTest`, `GlobalModuleRouteCoverageTest`, `TenantScopeBypassGuardTest`.
- **Suite complète finale** : 4 676 réussis, 12 ignorés, 3 échecs — les 3 échecs historiques `ControllerSizeInvariant` (aucun contrôleur ADR-819 en cause).
- **Build** : propre.

## 15. Recettes navigateur et E2E (clone isolé `leezr_audit819`, supprimé ensuite)

- **Recette Company 2.0** : **32/32** contrôles réussis (`acceptance.json`), sur clone propre :
  - catalogues ;
  - exiger depuis le catalogue ;
  - onglets du poste ;
  - application refusée en calcul par rôle ;
  - dossier d'embauche, historique, 409 ;
  - parrainage complet (réglages → recommandation → candidat mobile consenti → examen → conversion sans ressaisie → parrain sans accès).
- **E2E principaux du dépôt** rejoués contre le clone :
  - specs : ADR-771, 772, 792 (6 cas), 794, 795, 796, documents ;
  - **12/12 réussis**.
  - Au premier passage, 7 échecs : l'écran de démarrage n'était pas fini sous les délais de 10 à 15 s des specs, face au serveur `php -S` du clone. Avec des délais réalistes, tout passe.
  - Un vrai défaut de spec a été corrigé : texte présent deux fois dans `adr796-onboarding`.
  - Les captures de documentation réécrites par ces specs ont été restaurées.
- **Contrôle des données** :
  - empreinte DEV `bae623fe…` avant ;
  - après : identique une fois retirée la seule ligne de migration `000004` ajoutée volontairement.
  - Aucune société réelle modifiée ; sociétés 1 et 101 intactes sur DEV.

## 16. Compatibilité

- **Sociétés existantes** : activations, presets, demandes et documents inchangés ; calcul toujours par rôle tant qu'elles ne basculent pas.
- **Clients sans jeton de révision** : comportement inchangé.
- **Endpoints historiques** : tous conservés.
- **Migrations** : 4 nouvelles, réversibles.
  - deux ciblées sur le preset Logistique ;
  - deux de création ou d'ajout pour le Parrainage.
- **Inchangés** : Billing, paie, RBAC (3 nouvelles permissions de module, sans élargissement des rôles existants), `@core`.

## 17. Risques résiduels et décisions ouvertes

1. **Calcul par poste** : aucune société n'est basculée. À décider par chaque société après reprise de ses postes.
2. **Rôles existants trop larges** (`documents.configure` chez le Dispatcher de la société 101) : non corrigés, conformément à la règle « pas d'élargissement ni de réduction implicite ». Rapport ADR-818 toujours valable.
3. **Parrainage non tarifé** : décision Platform (tarif ou inclusion) avant tout usage réel. La plateforme doit aussi synchroniser le module sur l'environnement cible (`ModuleRegistry::sync`, catalogue de permissions).
4. **Portail candidat** :
   - la saisie du candidat et celle d'une RH sur place sur le MÊME portail ne sont pas arbitrées par révision : dernière saisie retenue AVANT validation ;
   - la validation reste explicite et montre les conflits avec la fiche.
5. **Preuve contractuelle** : prouvée par les tests et non rejouée dans le navigateur : la génération et la signature réelles sur clone dépassaient ce lot.
6. **Sources de satisfaction** : déclarées par les modules (une seule aujourd'hui) ; elles ne sont pas paramétrables poste par poste.
7. **Refonte visuelle des écrans existants** : limitée aux défauts trouvés par la revue. Une refonte plus profonde de Planning, Temps, Congés et Paie reste à arbitrer page par page.
