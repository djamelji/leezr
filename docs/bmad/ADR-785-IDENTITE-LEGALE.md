# ADR-785 — Identité légale Company · cohérence documentaire

> Base `16d4389a` · DEV uniquement · aucun push · aucune migration · aucun moteur nouveau · paie, cotisations et Billing intacts.
> Preuves : `docs/bmad/adr785/` (captures desktop/mobile) · tests listés au §6.

## 1. Sources de l'identité légale — avant / après

| Information | Avant | Après |
|---|---|---|
| Raison sociale, forme juridique, n° TVA | profil (via `CompanyLegalIdentity`) | inchangé |
| SIRET — contrats, documents, DSN | profil, colonne en repli | inchangé |
| SIRET — garde des bulletins officiels | **colonne `companies.siret` seule** | profil, colonne en repli |
| Code APE/NAF — saisie | **aucune** (colonne historique non éditable) | champ du profil « Code APE (NAF) » |
| Code APE/NAF — DSN | colonne seule | profil, colonne en repli, normalisé `6201Z` |
| Code APE/NAF — bulletins officiels (garde + PDF) | colonne (garde) / résolveur (PDF) | profil, colonne en repli (les deux) |
| Adresse de l'établissement | profil, colonne en repli | inchangé |
| Adresse de facturation | profil (facturation) | inchangé — jamais confondue avec l'adresse de l'établissement |

Non ajoutés (pas de besoin démontré par un consommateur) : code INSEE de commune, IDCC, effectif. Le nom commercial reste le nom de l'entreprise ; la raison sociale reste distincte.

## 2. Champ ajouté

`naf_code` — « Code APE (NAF) » / « APE / NAF code » ; portée Company ; marché FR ; format `NNNNL` ou `NN.NNL` (ex. 6201Z, 62.01Z), 6 caractères max ; validation serveur (règle `regex` générée depuis `pattern`) ; stocké dans le mécanisme canonique des champs dynamiques ; section « Informations administratives » du profil ; édition réservée à `settings.manage` (inchangé).

## 3. Compatibilité historique

- Lecture : la colonne historique reste le repli tant que le profil est vide ; aucune valeur inventée ; jamais déduite du JobDomain.
- Complétion explicite : `php artisan company:complete-legal-identity [--dry-run] [--company=ID]`
  - active le champ NAF pour les entreprises du marché FR qui ne l'ont pas (un champ désactivé par l'entreprise est respecté et signalé) ;
  - recopie les valeurs historiques **valides** (SIRET contrôlé Luhn, NAF au bon format, adresse) dans les champs **vides** du profil ; ne modifie jamais une valeur existante ni la colonne historique ; idempotente.
- DEV : exécutée — NAF activé pour 20 entreprises, aucune valeur recopiée ; second passage à blanc vide.

## 4. Consommateurs corrigés

| Consommateur | Correction |
|---|---|
| `DsnCompanyResolver` | NAF lu dans `CompanyLegalIdentity` (contrôles ADR-784 inchangés : obligatoire, format, refus explicite, aucun fichier ni déclaration en cas d'échec) |
| `GenerateOfficialPayslipsUseCase` (garde 4) | SIRET et NAF lus dans `CompanyLegalIdentity` ; mêmes messages ; aucun recalcul, montants/périodes/versions inchangés |
| `DsnDeclarationController` | refus 422 avec lien `fix` vers le profil et les champs à compléter |
| `CompanyLegalIdentity::contractRefusal` | lien `fix` avec les champs manquants |
| Profil Company | alerte « Pour générer vos documents et déclarations, complétez : … » (champs encore vides seulement), champs concernés mis en évidence, erreurs sous le champ en français, confirmation d'enregistrement, lien « Retour » vers le parcours initial (`?back=`) |
| Dialogue de contrat, page DSN, Paramètres RH › Contrats | message en langage clair + bouton « Compléter le profil de l'entreprise » qui ouvre le profil sur les champs concernés et permet le retour |

Documents historiques : non régénérés, non modifiés (les instantanés de génération restent la preuve).

## 5. Recette du profil

Navigateur (Playwright, compte propriétaire) : champ NAF visible ; arrivée avec `?missing=naf_code,company_city` → seule « Code APE (NAF) » est listée (la ville est renseignée) ; NAF « XX » → refus 422, message « Code APE (NAF) attendu : 4 chiffres et 1 lettre… » sous le champ, aucun texte technique ; mobile 390 px sans débordement. Rien n'a été enregistré en DEV (la seule écriture tentée a été refusée).

## 6. Tests

| Niveau | Résultat |
|---|---|
| `Adr785LegalIdentityTest` (profil autorisé/refusé, autre entreprise, NAF valide/invalide, fuite inter-entreprises, repli historique, absence non inventée, commande : à blanc, reprise valide, non-écrasement, valeurs invalides ignorées, champ désactivé respecté, idempotence ; refus actionnables) | 14/14 |
| `ExportPayrollDsnUseCaseTest` (+ NAF du profil sans colonne, profil prioritaire, NAF absent partout → refus sans déclaration) | 32/32 |
| `OfficialPayslipUseCaseTest` (+ SIRET/NAF du profil dans l'instantané, profil prioritaire, garde levée par le profil ; stockage factice) | 15/15 |
| E2E `adr785-legal-identity.spec.js` | 2/2 |
| Suite PHPUnit complète (une fois, après le dernier changement applicatif) | 4336 réussis, 12 ignorés, 4 échecs : **3 historiques `ControllerSizeInvariantTest`** (inchangés, non masqués) + `FieldDefinitionTest` qui comptait 45 définitions → 46 avec `naf_code` (attendu corrigé, rejoué : 17/17) |
| Build final | propre |

Générations irréversibles (contrats, DSN, bulletins) : uniquement en base SQLite mémoire et stockage factice. DEV partagé : aucun document généré (max id 33), documents UX3 26–33 présents, demandes de Bob 68/69/71/73 toujours annulées.

Non couvert : test HTTP du refus 422 de l'export DSN (le cas est couvert au niveau du cas d'usage et du lien `fix`).

## 7. Limites réglementaires

- Aucune conformité légale revendiquée pour les contrats, la DSN ou les bulletins.
- Le modèle de bulletin officiel n'affiche pas l'adresse de l'employeur (modèle non modifié dans ce lot).
- La DSN reste un export simulé ; aucune transmission officielle.
- Le format SIRET du profil n'est pas durci (14 chiffres + Luhn contrôlés à la DSN uniquement) pour ne pas bloquer l'enregistrement des profils existants.
- Le code NAF est contrôlé sur son format, pas sur son existence dans la nomenclature INSEE.

## 8. Bloquants de préproduction restants

Prestataire de signature en test ; DSN non transmise ; Stripe en sandbox et worker de staging ; dette `ControllerSizeInvariantTest` ; complétion des identités à exécuter (`company:complete-legal-identity`) puis saisie des NAF manquants par chaque entreprise.
