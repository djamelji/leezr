# ADR-812 — Corrections des anomalies P2 de la recette ADR-811

- **Date** : 2026-11-16, DEV uniquement, base `5c167e3d`.
- **Garanties** :
  - aucune migration ;
  - aucun droit ouvert, aucune permission retirée ;
  - aucun prix, abonnement ou règle RH modifié ;
  - aucun nouveau moteur ni nouvelle page.
- **Preuves navigateur** : `docs/bmad/adr812/`.

## Corrections

| ID | Avant | Après | Correction (réutilisation) |
|---|---|---|---|
| **C1** | Un nouvel invité recevait l'e-mail Laravel « Reset Password Notification… password reset request », en anglais, sans entreprise ni invitation. | « Votre accès à {entreprise} sur Leezr » : qui l'invite, « Activer mon compte », durée de validité du lien, que faire s'il a expiré. **Même jeton, même broker, même expiration (60 min)**, même page de mot de passe. | `AccountActivationNotification`, sur le modèle de `CompanyInvitationNotification`, appelée par `MembershipInvitation::sendNow` pour le type `account_setup`. La réinitialisation volontaire (« mot de passe oublié ») garde `ResetPassword`. |
| **C2** | Page du mot de passe : règles en anglais ; texte « différent des mots de passe utilisés précédemment » à la première connexion. | Règles traduites (FR et EN), texte neutre. | `usePasswordStrength` passe par i18n (`auth.passwordRules.*`). Les pages Company et Platform en bénéficient. |
| **A2** | « 5 companyModules.metric_employees inclus, puis 3 €/companyModules.metric_employees » | « 5 salarié inclus, puis 3 €/salarié » (FR), « employee » (EN) | Clé `companyModules.metric_employees` ajoutée. Le test vérifie **toutes** les unités de tarification des modules. |
| **C3** | « Appliquer la proposition « Logistique — rôles initiaux » » ne faisait rien sur un module que le preset ne couvre pas. | Le bouton n'est plus proposé ; à la place, « Aucune proposition disponible pour votre activité : choisissez vous-même. » | `ModuleSetupService::suggestion` : `available = false` quand la proposition ne donne aucune capacité du module. Les propositions utiles sont inchangées. |
| **D3** | Toast en anglais « Employee must have an active contract. » | « Ce collaborateur n'a pas de contrat actif. Activez son contrat depuis sa fiche (Documents & contrats) avant de le planifier. » | La règle serveur (I4) est inchangée. L'écran Planning traduit les deux refus connus ; le test garde les textes serveur et leur traduction alignés. |
| **D4** | « Demander un congé » sans solde ouvert : liste « Type » vide, aucune explication. | Message : aucun solde ouvert, le contrat doit d'abord être activé. Bouton d'envoi désactivé. | `VAlert` (stock Vuexy) dans le tiroir existant. Les soldes et types restent la seule source. |
| **F1** | Fiche d'une personne hors périmètre ouverte par URL : « Une erreur est survenue. Veuillez réessayer. » | « Fiche indisponible — Cette fiche n'existe pas ou ne fait pas partie de votre périmètre. », sans bouton Réessayer. | `person.store` conserve le code HTTP ; `ErrorState` existant (`show-retry=false`). Le serveur répond toujours 404 : rien n'est divulgué. |

## Non corrigé

- **C4, décision produit** : les noms de rôles par défaut de `JobdomainRegistry` (logistique) sont en anglais (« Dispatcher », « Driver », « Manager », « Operations Manager »), alors que « Propriétaire » est en français et que les presets publiés sont en français. Ce sont des données modifiables par chaque société ; seules les nouvelles sociétés seraient concernées.
- **Pied de page de tous les e-mails en anglais** (« Regards », « If you're having trouble… ») : gabarit Laravel et `APP_LOCALE=en` sans `lang/fr`. Il concerne tous les e-mails : P3, hors périmètre. Le nouvel e-mail porte sa propre formule (« À bientôt sur Leezr »).
- **Les P3 d'ADR-811** (codes d'audit, erreur console ApexCharts, formats acceptés, bloc documents de Mon activité).

## Tests

- **`Adr812UxCorrectionsTest`**, 3 tests :
  - unités de tarification et règles de mot de passe libellées ;
  - e-mail d'activation : objet, inviteur, durée, bouton, jeton réel du broker, pas de `ResetPassword` ;
  - refus expliqués : alignement des messages serveur avec le Planning, clés FR/EN, avertissement et bouton désactivé dans le tiroir de congé, 403/404 sur la fiche.
- **`Adr803ModuleSetupTest`** : nouveau test, une proposition qui ne donne rien n'est pas proposée.
- **Preuve de pertinence** : sur le code d'origine, les 4 tests échouent.
- **Tests existants ajustés** : les tests d'invitation (M7 lots 2, 3 et 4) attendent `AccountActivationNotification` pour l'invitation, et toujours `ResetPassword` pour « mot de passe oublié ». 219 tests d'invitation et de membres sont verts.
- **Navigateur** (société fictive 101, `adr812/*.jpg`) :
  - A2 : catalogue sans clé brute ;
  - C3 : pas de proposition vide (module Congés) ;
  - D3 : créneau pour Paul (sans contrat), 422 et message français ;
  - D4 : salariée sans contrat, message affiché et envoi désactivé ;
  - F1 : le chauffeur ouvre la fiche de Paul et voit « Fiche indisponible » sans Réessayer ;
  - C2 : règles en français ;
  - C1 : e-mail reçu dans Mailpit (jeton d'aperçu factice).
