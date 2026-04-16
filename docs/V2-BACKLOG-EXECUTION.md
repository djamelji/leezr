# V2-BACKLOG-EXECUTION — Backlog Technique Exécutable

> Transforme ADR-432 → ADR-437 en tickets DEV actionnables.
> Chaque tâche est ordonnée, précise, sans ambiguïté.
> Aucun code ne doit être modifié dans `@core/` ou `@layouts/`.

---

## Légende

- **[B]** = Backend (Laravel/PHP)
- **[F]** = Frontend (Vue 3 / Pinia)
- **[T]** = Tests
- **⊕** = Parallélisable avec la tâche précédente
- **→** = Dépend de la tâche précédente
- **Est.** = Estimation en heures

---

# ADR-432 — TENANCY : Global Scopes + BelongsToCompany

**Priorité** : P0 CRITIQUE SÉCURITÉ
**Effort total** : 4.5j (36h)
**Pré-requis** : Aucun

---

## 432-1 [B] Créer le trait BelongsToCompany + CompanyScope

**Description** : Créer un trait Eloquent et un global scope qui automatisent l'isolation multi-tenant. Le trait ajoute un `addGlobalScope` au boot du modèle et auto-set `company_id` au creating si un contexte company est actif.

**Fichiers impactés** :
- `app/Core/Traits/BelongsToCompany.php` (nouveau)
- `app/Core/Scopes/CompanyScope.php` (nouveau)

**Critères d'acceptation** :
- [ ] Le trait `BelongsToCompany` existe dans `app/Core/Traits/`
- [ ] Le trait boot un `CompanyScope` global scope via `addGlobalScope('company', new CompanyScope)`
- [ ] Le scope ajoute `where({table}.company_id, app('company.context')->id)` quand le binding existe
- [ ] Le scope ne fait rien quand `app()->bound('company.context')` est false (platform, CLI, tests)
- [ ] Le trait auto-set `company_id` sur `creating` si le modèle n'a pas déjà un company_id et que le binding existe
- [ ] Le trait expose `company(): BelongsTo` relation
- [ ] Le trait expose `scopeForCompany(Builder $query, Company $company)` pour override explicite

**Est.** : 2h

---

## 432-2 [B] Binder company.context dans SetCompanyContext

**Description** : Après validation du membership dans le middleware `SetCompanyContext`, binder l'instance Company dans le container applicatif via `app()->instance('company.context', $company)`. Unbind au terminate.

**Fichiers impactés** :
- `app/Company/Http/Middleware/SetCompanyContext.php` (modifier)

**Critères d'acceptation** :
- [ ] `app()->instance('company.context', $company)` est appelé après la ligne 117 (`$request->attributes->set('company', $company)`)
- [ ] Le middleware `terminate()` appelle `app()->forgetInstance('company.context')` pour éviter les fuites entre requêtes
- [ ] Les tests existants `CompanyAccessPolicyTest` passent toujours
- [ ] Le binding n'est PAS actif dans les routes platform (vérifier que `routes/platform.php` n'a pas `company.context` dans son middleware stack)

**Dépendance** : → 432-1
**Est.** : 1h

---

## 432-3 [B] Migrer les modèles Core (non-billing) vers BelongsToCompany

**Description** : Ajouter `use BelongsToCompany;` sur les modèles Core tenant-scoped qui ont un company_id non-nullable.

**Fichiers impactés** (16 fichiers) :
- `app/Core/Models/Membership.php`
- `app/Core/Models/Shipment.php`
- `app/Core/Modules/CompanyModule.php`
- `app/Core/Modules/CompanyModuleActivationReason.php`
- `app/Company/RBAC/CompanyRole.php`
- `app/Core/Jobdomains/CompanyPresetSnapshot.php`
- `app/Core/Support/SupportTicket.php`
- `app/Core/Support/SupportMessage.php`
- `app/Core/Notifications/NotificationPreference.php`
- `app/Core/Audit/CompanyAuditLog.php`
- `app/Core/Documents/CompanyDocument.php`
- `app/Core/Documents/MemberDocument.php`
- `app/Core/Documents/DocumentRequest.php`
- `app/Core/Documents/DocumentTypeActivation.php`
- `app/Core/Documents/CompanyDocumentSetting.php`

**Critères d'acceptation** :
- [ ] Chaque modèle listé a `use BelongsToCompany;`
- [ ] Si le modèle a déjà une relation `company()`, la supprimer (le trait la fournit)
- [ ] `php artisan test` passe (aucune régression)

**Dépendance** : → 432-2
**Est.** : 3h

---

## 432-4 [B] Migrer les modèles Billing vers BelongsToCompany

**Description** : Ajouter `use BelongsToCompany;` sur les 17 modèles Billing. Attention particulière aux modèles utilisés dans les webhooks Stripe (ils opèrent hors contexte company).

**Fichiers impactés** (17 fichiers) :
- `app/Core/Billing/Invoice.php`
- `app/Core/Billing/Payment.php`
- `app/Core/Billing/Subscription.php`
- `app/Core/Billing/CompanyWallet.php`
- `app/Core/Billing/CompanyWalletTransaction.php`
- `app/Core/Billing/CompanyPaymentProfile.php`
- `app/Core/Billing/CompanyPaymentCustomer.php`
- `app/Core/Billing/CompanyAddonSubscription.php`
- `app/Core/Billing/CompanyEntitlements.php`
- `app/Core/Billing/LedgerEntry.php`
- `app/Core/Billing/ScheduledDebit.php`
- `app/Core/Billing/BillingCheckoutSession.php`
- `app/Core/Billing/BillingExpectedConfirmation.php`
- `app/Core/Billing/CreditNote.php`
- `app/Core/Billing/InvoiceLine.php`
- `app/Core/Billing/FinancialSnapshot.php`
- `app/Core/Billing/PlanChangeIntent.php`

**Critères d'acceptation** :
- [ ] Chaque modèle listé a `use BelongsToCompany;`
- [ ] `StripeEventProcessor` utilise `withoutGlobalScope('company')` dans ses lookups (vérifier/ajouter)
- [ ] Les commandes billing artisan (`billing:renew`, `billing:process-dunning`, etc.) fonctionnent — elles opèrent hors HTTP donc le binding n'est pas actif = scope inactif = OK
- [ ] `php artisan test` passe

**Dépendance** : → 432-2 ⊕ 432-3
**Est.** : 3h

---

## 432-5 [B] Gérer les modèles avec company_id nullable

**Description** : Certains modèles ont un `company_id` nullable (platform-owned + company-owned). Le trait doit gérer ce cas : le scope ne s'applique QUE quand le binding existe ET que le modèle est dans un contexte company.

**Fichiers impactés** :
- `app/Core/Ai/AiRequestLog.php` — company_id nullable
- `app/Core/Fields/FieldDefinition.php` — company_id nullable (platform = null, company = ID)
- `app/Core/Fields/FieldActivation.php` — company_id nullable
- `app/Core/Documents/DocumentType.php` — company_id nullable (platform catalog + company custom)
- `app/Core/Notifications/NotificationEvent.php` — company_id nullable
- `app/Core/Traits/BelongsToCompany.php` — ajouter option `$companyIdNullable = false`

**Critères d'acceptation** :
- [ ] Le trait accepte une property `protected bool $companyIdNullable = false;` overridable dans le modèle
- [ ] Si `$companyIdNullable = true`, le scope ajoute `where(company_id = X OR company_id IS NULL)` — pour voir les éléments company + les éléments platform
- [ ] Les 5 modèles ci-dessus ont `use BelongsToCompany;` avec `protected bool $companyIdNullable = true;`
- [ ] Les ReadModels platform qui listent TOUS les FieldDefinition (toutes companies) utilisent `withoutGlobalScope('company')`
- [ ] `php artisan test` passe

**Dépendance** : → 432-3
**Est.** : 3h

---

## 432-6 [B] Adapter les ReadModels — supprimer where redondants

**Description** : Les ReadModels company-scoped ajoutent manuellement `->where('company_id', $company->id)`. Avec le global scope, ces where sont redondants. Les supprimer pour simplifier le code. Garder le paramètre `Company $company` dans la signature (documentation + binding dans le middleware).

**Fichiers impactés** :
- `app/Modules/Logistics/Shipments/ReadModels/ShipmentReadModel.php`
- `app/Modules/Logistics/Shipments/ReadModels/MyDeliveryReadModel.php`
- `app/Core/Billing/ReadModels/CompanyBillingReadService.php`
- `app/Core/Documents/ReadModels/CompanyDocumentReadModel.php`
- `app/Core/Documents/ReadModels/CompanyDocumentActivationReadModel.php`
- `app/Core/Documents/ReadModels/DocumentRequestQueueReadModel.php`
- `app/Company/Fields/ReadModels/CompanyUserProfileReadModel.php`
- `app/Company/Fields/ReadModels/CompanyProfileReadModel.php`

**Critères d'acceptation** :
- [ ] Les `->where('company_id', $company->id)` explicites sont supprimés dans les ReadModels listés
- [ ] Le paramètre `Company $company` reste dans les signatures (ne pas le retirer)
- [ ] `php artisan test` passe — le scope global fait le même travail
- [ ] Aucun ReadModel platform n'est modifié (ils n'ont pas de company scope)

**Dépendance** : → 432-3, 432-4
**Est.** : 3h

---

## 432-7 [B] Adapter les platform controllers — withoutGlobalScope

**Description** : Les controllers platform admin qui listent des données cross-company doivent explicitement désactiver le scope. Vérifier et ajouter `withoutGlobalScope('company')` là où nécessaire.

**Fichiers impactés** :
- `app/Modules/Platform/Companies/Http/CompanyController.php`
- `app/Modules/Platform/Billing/Http/PlatformBillingController.php`
- `app/Core/Billing/Stripe/StripeEventProcessor.php`
- `app/Core/Billing/ReadModels/PlatformBillingReadService.php`
- `app/Modules/Platform/AI/Http/PlatformAiController.php` (usage stats cross-company)
- Tout controller platform qui requête des modèles tenant-scoped

**Critères d'acceptation** :
- [ ] `StripeEventProcessor` utilise `withoutGlobalScope('company')` pour ses lookups par `provider_payment_id`
- [ ] Les platform ReadModels cross-company utilisent `withoutGlobalScope('company')`
- [ ] Les commandes artisan billing/documents fonctionnent (pas de binding = scope inactif = OK, mais vérifier)
- [ ] `php artisan test` passe

**Dépendance** : → 432-4
**Est.** : 3h

---

## 432-8 [T] Tests cross-tenant exhaustifs

**Description** : Créer un test d'invariant qui vérifie que tout modèle avec `company_id` dans sa migration utilise le trait `BelongsToCompany`. Créer des tests cross-tenant pour les endpoints critiques.

**Fichiers impactés** :
- `tests/Feature/TenancyInvariantTest.php` (nouveau)
- `tests/Feature/CrossTenantIsolationTest.php` (nouveau)

**Critères d'acceptation** :
- [ ] `TenancyInvariantTest::test_all_models_with_company_id_use_belongs_to_company_trait()` — scanne les migrations pour `company_id`, vérifie que le modèle correspondant a le trait
- [ ] `CrossTenantIsolationTest` — créer 2 companies avec chacune un member, vérifier que les données de l'une ne fuient pas vers l'autre via les endpoints critiques : `/shipments`, `/billing/invoices`, `/company/members`, `/company/documents`
- [ ] `CrossTenantIsolationTest::test_webhook_validates_company_id()` — vérifier que StripeEventProcessor ne cross-contamine pas
- [ ] Tous les tests existants passent
- [ ] `php artisan test` full suite green

**Dépendance** : → 432-6, 432-7
**Est.** : 6h

---

## 432 — Ordre d'exécution

```
432-1 (trait+scope)
  → 432-2 (binding middleware)
    → 432-3 (modèles core)          ⊕ 432-4 (modèles billing)
      → 432-5 (nullable)
    → 432-6 (ReadModels cleanup)     ⊕ 432-7 (platform withoutScope)
      → 432-8 (tests)
```

---

# ADR-433 — RBAC : Permissions Frontend

**Priorité** : P1 URGENT
**Effort total** : 4.5j (36h)
**Pré-requis** : Aucun (parallélisable avec ADR-432)

---

## 433-1 [B] Enrichir /me company avec permissions[]

**Description** : L'endpoint company `/me` retourne actuellement `user`, `ui_theme`, `ui_session`, `theme_preference` mais PAS les permissions. Ajouter `permissions`, `is_owner`, `is_administrative` directement dans la réponse.

**Fichiers impactés** :
- `app/Modules/Infrastructure/Auth/Http/AuthController.php` — méthode `me()`

**Critères d'acceptation** :
- [ ] La réponse `/me` contient `'permissions' => $membership->companyRole?->permissions->pluck('key')->toArray() ?? []`
- [ ] La réponse contient `'is_owner' => $membership->isOwner()`
- [ ] La réponse contient `'is_administrative' => $membership->isAdmin()`
- [ ] Le authStore frontend reçoit et stocke ces données
- [ ] `php artisan test` passe

**Est.** : 2h

---

## 433-2 [F] Mettre à jour le authStore pour consommer permissions depuis /me

**Description** : Le authStore infère actuellement les permissions depuis `company_role.permissions` dans la réponse `/my-companies`. Modifier `fetchMe()` pour hydrater les permissions depuis la réponse `/me` enrichie.

**Fichiers impactés** :
- `resources/js/core/stores/auth.js` — action `fetchMe()`, getter `permissions`

**Critères d'acceptation** :
- [ ] `fetchMe()` stocke `response.permissions` dans un state `_permissions`
- [ ] Le getter `permissions` lit `_permissions` au lieu de `company?.company_role?.permissions`
- [ ] `is_owner` et `is_administrative` sont mis à jour depuis la réponse
- [ ] Le getter `hasPermission(key)` fonctionne identiquement (owner = true, sinon check array)
- [ ] Aucune régression sur les fonctionnalités existantes

**Dépendance** : → 433-1
**Est.** : 2h

---

## 433-3 [F] Créer le composable useCan()

**Description** : Créer un composable centralisé qui expose les vérifications de permissions de manière ergonomique. Remplace l'usage direct du store dans les composants.

**Fichiers impactés** :
- `resources/js/composables/useCan.js` (nouveau)

**Critères d'acceptation** :
- [ ] Exporte `useCan()` retournant `{ can, canAll, canAny, canModule, isOwner, isAdmin }`
- [ ] `can(permission)` → `authStore.hasPermission(permission)`
- [ ] `canAll(...permissions)` → toutes les permissions requises
- [ ] `canAny(...permissions)` → au moins une permission
- [ ] `canModule(moduleKey)` → `moduleStore.isActive(moduleKey)`
- [ ] `isOwner` → computed depuis authStore
- [ ] `isAdmin` → computed depuis authStore
- [ ] Fonctionne dans les composants Vue (reactive)

**Dépendance** : → 433-2
**Est.** : 2h

---

## 433-4 [F] Créer la directive v-can

**Description** : Créer une directive Vue globale `v-can` qui masque un élément DOM si l'utilisateur n'a pas la permission spécifiée.

**Fichiers impactés** :
- `resources/js/plugins/directives/can.js` (nouveau)
- `resources/js/plugins/directives/index.js` (modifier pour enregistrer la directive)

**Critères d'acceptation** :
- [ ] `v-can="'documents.manage'"` masque l'élément si permission absente (`display: none`)
- [ ] `v-can.disable="'documents.manage'"` désactive l'élément au lieu de le masquer (ajoute `disabled` + `opacity: 0.5`)
- [ ] Owner bypass : l'élément est toujours visible pour les owners
- [ ] La directive est reactive : si les permissions changent (via SSE refresh), l'élément se met à jour
- [ ] Enregistrée globalement via le plugin directives

**Dépendance** : → 433-2
**Est.** : 2h

---

## 433-5 [F] Gater les actions CRUD — pages Members

**Description** : Appliquer `v-can` ou `useCan()` sur toutes les actions CRUD de la page Members.

**Fichiers impactés** :
- `resources/js/pages/company/members/index.vue` (ou `[tab].vue`)
- Sous-composants `_Members*.vue`

**Critères d'acceptation** :
- [ ] Bouton "Inviter un membre" : `v-can="'members.invite'"`
- [ ] Bouton "Modifier" sur chaque membre : `v-can="'members.manage'"`
- [ ] Bouton "Supprimer" : `v-can="'members.manage'"`
- [ ] Lien "Identifiants" : `v-can="'members.credentials'"`
- [ ] Les actions sont masquées, pas juste désactivées
- [ ] L'owner voit tout

**Dépendance** : → 433-3, 433-4
**Est.** : 3h

---

## 433-6 [F] Gater les actions CRUD — pages Documents

**Description** : Appliquer le gating permissions sur les pages Documents.

**Fichiers impactés** :
- `resources/js/pages/company/documents/_DocumentsRequests.vue`
- `resources/js/pages/company/documents/_DocumentsSettings.vue`
- Autres sous-composants documents

**Critères d'acceptation** :
- [ ] Actions upload/review : `v-can="'documents.manage'"`
- [ ] Actions configure : `v-can="'documents.configure'"`
- [ ] Actions view sont accessibles avec `documents.view`
- [ ] Les boutons bulk actions sont gatés
- [ ] L'owner voit tout

**Dépendance** : → 433-3, 433-4
**Est.** : 3h

---

## 433-7 [F] Gater les actions CRUD — pages Billing + Settings + Roles + Shipments

**Description** : Appliquer le gating sur les pages restantes.

**Fichiers impactés** :
- `resources/js/pages/company/billing/` — toutes les pages (manage, checkout)
- `resources/js/pages/company/settings/` — settings.manage
- `resources/js/pages/company/roles/` — roles.view, roles.manage
- `resources/js/pages/company/shipments/` — shipments.view, create, manage_status, assign
- `resources/js/pages/company/audit/` — audit.view
- `resources/js/pages/company/support/` — support.view, create
- `resources/js/pages/company/modules/` — modules.manage

**Critères d'acceptation** :
- [ ] Chaque action CRUD dans chaque page listée est gatée par la permission correspondante
- [ ] Les pages billing (admin-only) vérifient `isAdmin` en plus de la permission
- [ ] Le pattern est cohérent : `v-can` pour les boutons, `useCan()` pour la logique conditionnelle
- [ ] L'owner voit tout
- [ ] Aucune page n'affiche d'action non autorisée

**Dépendance** : → 433-3, 433-4 ⊕ 433-5 ⊕ 433-6
**Est.** : 6h

---

## 433-8 [F] Compléter les route meta.permission

**Description** : Vérifier et compléter les `meta.permission` et `meta.module` sur toutes les routes company. Le route guard doit empêcher la navigation vers une page non autorisée.

**Fichiers impactés** :
- `resources/js/pages/company/**/*.vue` — tous les `definePage()` avec meta incomplètes

**Critères d'acceptation** :
- [ ] Chaque page company avec des données protégées a `meta: { permission: 'xxx.view' }` ou `meta: { surface: 'structure' }`
- [ ] Chaque page company a `meta: { module: 'core.xxx' }` si liée à un module
- [ ] Le route guard dans `guards.js` redirige vers 403 si permission manquante
- [ ] Les pages sans restriction (dashboard) n'ont pas de meta.permission
- [ ] Pas de régression sur la navigation

**Dépendance** : → 433-3
**Est.** : 3h

---

## 433-9 [F] Page 403 contextuelle

**Description** : Remplacer le redirect 403 générique par une page dédiée qui indique quelle permission manque.

**Fichiers impactés** :
- `resources/js/pages/company/403.vue` (si inexistant, ou modifier existant)
- `resources/js/plugins/1.router/guards.js` — passer la permission manquante dans query params

**Critères d'acceptation** :
- [ ] La page 403 affiche le nom de la permission manquante (ex: "documents.manage")
- [ ] La page 403 affiche le rôle actuel de l'utilisateur
- [ ] La page affiche un message "Contactez votre administrateur pour obtenir cette permission"
- [ ] Bouton "Retour au dashboard"
- [ ] Utilise un preset Vuexy existant (pages/misc ou pages/errors)

**Dépendance** : → 433-8
**Est.** : 2h

---

## 433-10 [T] Tests RBAC frontend

**Description** : Vérifier côté backend que le /me retourne bien les permissions.

**Fichiers impactés** :
- `tests/Feature/AuthMePermissionsTest.php` (nouveau)

**Critères d'acceptation** :
- [ ] Test que `/me` retourne `permissions` array pour un membre avec rôle
- [ ] Test que `/me` retourne `is_owner: true` pour le owner
- [ ] Test que `/me` retourne `is_administrative: true` pour un admin
- [ ] Test qu'un membre sans rôle reçoit un array permissions vide
- [ ] `php artisan test` passe

**Dépendance** : → 433-1
**Est.** : 2h

---

## 433 — Ordre d'exécution

```
433-1 (enrichir /me)       ⊕ 433-10 (tests backend)
  → 433-2 (authStore)
    → 433-3 (useCan)       ⊕ 433-4 (v-can directive)
      → 433-5 (members)    ⊕ 433-6 (documents) ⊕ 433-7 (autres pages)
      → 433-8 (route meta)
        → 433-9 (page 403)
```

---

# ADR-434 — REALTIME : Contrat SSE Global

**Priorité** : P1 URGENT
**Effort total** : 6j (48h)
**Pré-requis** : ADR-433 (pour rbac.changed → /me refresh)

---

## 434-1 [F] Créer le fichier topicHandlers.js centralisé

**Description** : Créer un fichier qui mappe chaque topic SSE vers son handler frontend. Ce fichier est la source de vérité pour le comportement SSE.

**Fichiers impactés** :
- `resources/js/core/realtime/topicHandlers.js` (nouveau)

**Critères d'acceptation** :
- [ ] Exporte un objet `topicHandlers` avec une entrée par topic consommé
- [ ] Chaque handler est une fonction `(envelope, stores) => void`
- [ ] Les handlers pour les topics existants (`document.updated`, `automation.run.completed`) sont migrés ici (ne dupliquent pas la logique existante des stores, appellent les méthodes existantes)
- [ ] Les 5 topics critiques ont des handlers : `rbac.changed`, `modules.changed`, `plan.changed`, `billing.updated`, `notification.created`
- [ ] Structure extensible : ajouter un topic = ajouter une entrée

**Est.** : 3h

---

## 434-2 [F] Brancher topicHandlers dans le runtime

**Description** : Modifier le DomainHandler dans le runtime pour dispatcher les événements via le fichier centralisé `topicHandlers.js` en plus du DomainEventBus existant.

**Fichiers impactés** :
- `resources/js/core/runtime/runtime.js` — méthode `_initRealtime()` et `_initPlatformRealtime()`
- `resources/js/core/realtime/ChannelRouter.js` (si nécessaire)

**Critères d'acceptation** :
- [ ] Les domain events passent par `topicHandlers[topic]` SI un handler existe
- [ ] Le `DomainEventBus` continue de fonctionner (les `useRealtimeSubscription` existants ne cassent pas)
- [ ] Les handlers dans `topicHandlers.js` et les subscriptions via `useRealtimeSubscription` coexistent
- [ ] Aucune régression sur les SSE documents et automations existants

**Dépendance** : → 434-1
**Est.** : 3h

---

## 434-3 [F] Handler rbac.changed → refresh permissions

**Description** : Quand le topic `rbac.changed` est reçu, appeler `authStore.fetchMe()` pour rafraîchir les permissions. Afficher un toast informatif.

**Fichiers impactés** :
- `resources/js/core/realtime/topicHandlers.js` — handler `rbac.changed`
- `resources/js/core/stores/auth.js` — s'assurer que `fetchMe()` met à jour `_permissions`

**Critères d'acceptation** :
- [ ] Quand un admin change le rôle d'un utilisateur connecté, les permissions de cet utilisateur sont rafraîchies en < 3s
- [ ] Un toast `"Vos permissions ont été mises à jour"` s'affiche
- [ ] Les éléments gatés par `v-can` se mettent à jour automatiquement (la directive est reactive)
- [ ] Le handler ne déclenche PAS de refetch si le `user_id` dans l'envelope ne correspond pas à l'utilisateur courant (optimisation)

**Dépendance** : → 434-2, ADR-433-2
**Est.** : 3h

---

## 434-4 [F] Handler modules.changed → refresh navigation

**Description** : Quand le topic `modules.changed` est reçu, rafraîchir le moduleStore et la navigation.

**Fichiers impactés** :
- `resources/js/core/realtime/topicHandlers.js` — handler `modules.changed`
- `resources/js/core/stores/module.js` — ajouter `fetchModules({ silent: true })`
- `resources/js/core/stores/nav.js` — ajouter `refresh()` ou equivalent

**Critères d'acceptation** :
- [ ] Quand un module est activé/désactivé, la navigation se met à jour en temps réel
- [ ] Le moduleStore est rafraîchi (isActive() retourne la bonne valeur)
- [ ] Un toast informatif s'affiche ("Module X activé/désactivé")
- [ ] Pas de skeleton/blink pendant le refresh (silent mode)

**Dépendance** : → 434-2
**Est.** : 3h

---

## 434-5 [F] Handler plan.changed + billing.updated → refresh billing

**Description** : Quand le topic `plan.changed` ou `billing.updated` est reçu, rafraîchir le billingStore.

**Fichiers impactés** :
- `resources/js/core/realtime/topicHandlers.js` — handlers `plan.changed`, `billing.updated`
- `resources/js/modules/company/billing/billing.store.js` — ajouter `fetchOverview({ silent: true })`

**Critères d'acceptation** :
- [ ] Quand un paiement est traité, le billing overview se rafraîchit
- [ ] Quand le plan change, le billing overview se rafraîchit
- [ ] Le refetch est silencieux (pas de skeleton)
- [ ] Si l'utilisateur est sur la page billing, les données sont à jour en temps réel

**Dépendance** : → 434-2
**Est.** : 3h

---

## 434-6 [F] Handler notification.created → badge temps réel

**Description** : Quand le topic `notification.created` est reçu, incrémenter le badge notification dans la navbar et stocker la notification dans le store.

**Fichiers impactés** :
- `resources/js/core/realtime/topicHandlers.js` — handler `notification.created`
- `resources/js/core/stores/notification.js` — ajouter `append(notification)`, `unreadCount` getter

**Critères d'acceptation** :
- [ ] Le badge notification dans la navbar s'incrémente en temps réel
- [ ] La notification est ajoutée au store (append, pas refetch)
- [ ] Le payload contient `title`, `body`, `icon`, `severity`, `link`
- [ ] Cliquer sur la notification navigue vers `link`
- [ ] Le badge est scope-aware (company vs platform)

**Dépendance** : → 434-2
**Est.** : 4h

---

## 434-7 [F] Handlers secondaires — members, jobdomain, audit

**Description** : Brancher les topics restants qui ont un comportement utile.

**Fichiers impactés** :
- `resources/js/core/realtime/topicHandlers.js` — handlers pour `members.changed`, `member.joined`, `member.removed`, `jobdomain.changed`, `audit.logged`
- `resources/js/modules/company/members/members.store.js` — ajouter `fetchMembers({ silent: true })`
- `resources/js/modules/company/settings/settings.store.js` — ajouter refetch jobdomain

**Critères d'acceptation** :
- [ ] `members.changed` / `member.joined` / `member.removed` → `membersStore.fetchMembers({ silent: true })` si sur la page members
- [ ] `member.joined` → toast "X a rejoint l'équipe"
- [ ] `jobdomain.changed` → refresh settings + navigation
- [ ] `audit.logged` → `auditStore.fetchLogs({ silent: true })` si sur la page audit
- [ ] Tous les refetch sont silencieux (smart merge pattern du documents store)

**Dépendance** : → 434-2
**Est.** : 5h

---

## 434-8 [F] Fallback polling pour stores critiques

**Description** : Quand le SSE est déconnecté (fallback activé par RealtimeClient), les stores critiques doivent passer en mode polling.

**Fichiers impactés** :
- `resources/js/core/runtime/runtime.js` — écouter `onFallback()` et activer le polling sur les stores critiques
- `resources/js/core/stores/auth.js` — ajouter polling interval
- `resources/js/modules/company/billing/billing.store.js` — ajouter polling
- `resources/js/modules/company/members/members.store.js` — ajouter polling

**Critères d'acceptation** :
- [ ] Quand le SSE passe en fallback, un polling de 30s est activé sur auth, billing, members
- [ ] Quand le SSE se reconnecte, le polling s'arrête
- [ ] Le polling utilise le même pattern `{ silent: true }` que le SSE
- [ ] Les intervalles : auth 60s, billing 60s, members 120s

**Dépendance** : → 434-3, 434-5
**Est.** : 4h

---

## 434-9 [F] Connection status indicator

**Description** : Ajouter un indicateur discret dans le footer ou la navbar qui montre l'état de la connexion SSE.

**Fichiers impactés** :
- `resources/js/core/realtime/useRealtimeStatus.js` (nouveau composable)
- Le layout default ou le composant navbar — ajouter un dot indicator

**Critères d'acceptation** :
- [ ] Vert = SSE connecté
- [ ] Jaune = reconnecting (animation pulse)
- [ ] Rouge = déconnecté / polling mode
- [ ] L'indicateur est un petit dot (6px) discret, pas intrusif
- [ ] Tooltip au hover indiquant l'état
- [ ] Utilise le state du RealtimeClient (exposer `connectionStatus` reactive)

**Dépendance** : → 434-2
**Est.** : 3h

---

## 434 — Ordre d'exécution

```
434-1 (topicHandlers.js)
  → 434-2 (brancher runtime)
    → 434-3 (rbac.changed)     ⊕ 434-4 (modules.changed)   ⊕ 434-5 (billing)
    → 434-6 (notifications)    ⊕ 434-7 (handlers secondaires)
    → 434-8 (fallback polling)
    → 434-9 (status indicator)
```

---

# ADR-435 — MULTI-MARKET : Frontend Market-Aware

**Priorité** : P2 REQUIS
**Effort total** : 4.5j (36h)
**Pré-requis** : Aucun (parallélisable)

---

## 435-1 [B] Enrichir /my-companies avec market data

**Description** : Ajouter les données market dans la réponse de l'endpoint `/my-companies`.

**Fichiers impactés** :
- `app/Company/Fields/ReadModels/UserCompaniesReadModel.php` (ou le ReadModel équivalent utilisé par le endpoint)
- Le controller qui appelle ce ReadModel

**Critères d'acceptation** :
- [ ] La réponse `/my-companies` contient pour chaque company : `market_key`, `market: { currency, locale, timezone, dial_code }`
- [ ] Le market est résolu via `MarketResolver::resolveForCompany()`
- [ ] Si la company n'a pas de market_key, le market par défaut est utilisé
- [ ] `php artisan test` passe

**Est.** : 2h

---

## 435-2 [B] Enrichir /me company avec market data

**Description** : Ajouter le market de la company active dans la réponse `/me`.

**Fichiers impactés** :
- `app/Modules/Infrastructure/Auth/Http/AuthController.php` — méthode `me()`

**Critères d'acceptation** :
- [ ] La réponse `/me` contient `'market' => ['key' => $market->key, 'currency' => $market->currency, 'locale' => $market->locale, 'timezone' => $market->timezone, 'dial_code' => $market->dial_code]`
- [ ] `php artisan test` passe

**Dépendance** : ⊕ 435-1
**Est.** : 1h

---

## 435-3 [F] Appeler applyMarket au boot et switch

**Description** : Brancher `worldStore.applyMarket()` dans le flow de boot du runtime et dans le handler de company switch.

**Fichiers impactés** :
- `resources/js/core/runtime/runtime.js` — dans le boot et dans `switchCompany()`
- `resources/js/core/stores/auth.js` — s'assurer que `currentCompany` expose `market`

**Critères d'acceptation** :
- [ ] Au boot company, après `fetchMe()` et `fetchMyCompanies()`, appeler `worldStore.applyMarket(authStore.currentCompany.market)`
- [ ] Au switch company, après le reboot, appeler `worldStore.applyMarket(authStore.currentCompany.market)`
- [ ] Le worldStore a maintenant la bonne currency/locale/timezone pour la company active
- [ ] `formatMoney()` et `formatDateTime()` utilisent les bonnes valeurs
- [ ] Test manuel : switcher FR→GB, vérifier que les montants passent de "€" à "£"

**Dépendance** : → 435-1
**Est.** : 3h

---

## 435-4 [F] Créer le composable useMarketFormatting

**Description** : Créer un composable qui expose des fonctions de formatage liées au market actif.

**Fichiers impactés** :
- `resources/js/composables/useMarketFormatting.js` (nouveau)

**Critères d'acceptation** :
- [ ] Exporte `useMarketFormatting()` retournant `{ formatAmount, formatDate, formatDateTime, currency, locale, timezone }`
- [ ] `formatAmount(cents, currency?)` utilise `formatMoney(cents, { currency: currency || world.currency })`
- [ ] `formatDate(iso)` utilise `formatDate(iso, { locale: world.locale, timeZone: world.timezone })`
- [ ] `currency`, `locale`, `timezone` sont des computed réactifs
- [ ] Le composable est utilisable dans n'importe quel composant Vue

**Dépendance** : → 435-3
**Est.** : 2h

---

## 435-5 [F] Auditer les pages billing — forcer currency explicite

**Description** : Vérifier toutes les pages billing et s'assurer que `formatMoney()` reçoit toujours `{ currency }` explicitement depuis la donnée API, pas depuis le worldStore fallback.

**Fichiers impactés** :
- `resources/js/pages/company/billing/_BillingPlan.vue`
- `resources/js/pages/company/billing/_BillingInvoices.vue`
- `resources/js/pages/company/billing/_BillingPayments.vue`
- `resources/js/views/shared/billing/NextInvoicePreview.vue`
- Tout composant qui affiche un montant monétaire

**Critères d'acceptation** :
- [ ] Chaque appel `formatMoney()` dans les pages billing passe `{ currency: data.currency }` explicitement
- [ ] Aucun appel `formatMoney(amount)` sans currency dans les contextes billing
- [ ] Les widgets dashboard qui affichent des montants passent aussi la currency
- [ ] Vérification visuelle : les montants sont corrects pour une company FR (EUR) et GB (GBP)

**Dépendance** : → 435-3
**Est.** : 4h

---

## 435-6 [B] Remplacer FX rates stubs par API réelle

**Description** : Remplacer les taux hardcodés dans `FxRateFetchJob` par un appel à l'API ECB (gratuit, pas de clé API).

**Fichiers impactés** :
- `app/Core/Markets/Jobs/FxRateFetchJob.php`

**Critères d'acceptation** :
- [ ] Le job appelle l'API ECB (`https://data-api.ecb.europa.eu/service/data/EXR/...`)
- [ ] Fallback aux stubs si l'API est indisponible (graceful degradation)
- [ ] Les taux sont stockés dans la table `fx_rates`
- [ ] Le job est idempotent (peut tourner toutes les 6h sans problème)
- [ ] `php artisan test` passe
- [ ] Log en cas d'échec API (warning, pas error)

**Dépendance** : aucune ⊕ 435-1
**Est.** : 4h

---

## 435-7 [T] Tests multi-market

**Description** : Créer des tests vérifiant le bon fonctionnement multi-market.

**Fichiers impactés** :
- `tests/Feature/MultiMarketTest.php` (nouveau)

**Critères d'acceptation** :
- [ ] Test que `/my-companies` retourne `market_key` et `market` pour chaque company
- [ ] Test que `/me` retourne `market` avec currency, locale, timezone
- [ ] Test que la facturation utilise la currency du market de la company
- [ ] Test que le TaxContextResolver fonctionne pour FR, GB, et cross-market
- [ ] `php artisan test` passe

**Dépendance** : → 435-1, 435-2
**Est.** : 4h

---

## 435 — Ordre d'exécution

```
435-1 (enrichir /my-companies) ⊕ 435-2 (enrichir /me) ⊕ 435-6 (FX rates)
  → 435-3 (applyMarket frontend)
    → 435-4 (useMarketFormatting)
    → 435-5 (audit pages billing)
  → 435-7 (tests)
```

---

# ADR-436 — AI ENGINE : Extension Multi-Module

**Priorité** : P2 REQUIS
**Effort total** : 7j (56h)
**Pré-requis** : ADR-432 (BelongsToCompany pour AiRequestLog)

---

## 436-1 [B] Créer l'interface AiModuleContract

**Description** : Créer l'interface que chaque module doit implémenter pour brancher l'AI.

**Fichiers impactés** :
- `app/Core/Ai/Contracts/AiModuleContract.php` (nouveau)

**Critères d'acceptation** :
- [ ] Interface avec méthodes : `moduleKey(): string`, `policyFields(): array`, `resolvePolicy(int $companyId): AiPolicy`, `dispatchAnalysis(Model $entity): void`
- [ ] `policyFields()` retourne un array de champs configurables pour l'admin UI (type boolean, int, etc.)
- [ ] Documenté avec PHPDoc clair

**Est.** : 2h

---

## 436-2 [B] Créer le AiModuleContractRegistry

**Description** : Créer un registre singleton qui agrège les implémentations de AiModuleContract.

**Fichiers impactés** :
- `app/Core/Ai/AiModuleContractRegistry.php` (nouveau)

**Critères d'acceptation** :
- [ ] `register(AiModuleContract $module): void` — enregistre un module
- [ ] `get(string $moduleKey): ?AiModuleContract` — retourne le module par clé
- [ ] `all(): array` — retourne tous les modules enregistrés
- [ ] `has(string $moduleKey): bool`
- [ ] Enregistré dans un ServiceProvider (boot)

**Dépendance** : → 436-1
**Est.** : 2h

---

## 436-3 [B] Refactorer AiPolicyResolver → Registry

**Description** : Remplacer le `match($moduleKey)` hardcodé dans AiPolicyResolver par un appel au registry.

**Fichiers impactés** :
- `app/Core/Ai/AiPolicyResolver.php`

**Critères d'acceptation** :
- [ ] Le `match` est remplacé par `AiModuleContractRegistry::get($moduleKey)?->resolvePolicy($companyId) ?? AiPolicy::disabled()`
- [ ] Le comportement existant pour Documents est identique (aucune régression)
- [ ] `php artisan test` passe

**Dépendance** : → 436-2
**Est.** : 2h

---

## 436-4 [B] Migrer Documents vers AiModuleContract

**Description** : Créer une implémentation `DocumentsAiModule` qui implémente AiModuleContract et encapsule la logique existante.

**Fichiers impactés** :
- `app/Modules/Core/Documents/AI/DocumentsAiModule.php` (nouveau)
- Le ServiceProvider du module Documents — enregistrer dans le registry

**Critères d'acceptation** :
- [ ] `moduleKey()` retourne `'documents'`
- [ ] `policyFields()` retourne les champs existants (ai_analysis_enabled, ocr_enabled, etc.)
- [ ] `resolvePolicy()` reprend la logique existante (CompanyDocumentSetting.ai_features)
- [ ] `dispatchAnalysis()` dispatch `ProcessDocumentAiJob`
- [ ] Enregistré dans le ServiceProvider au boot
- [ ] Comportement identique à avant — aucune régression
- [ ] `php artisan test` passe

**Dépendance** : → 436-3
**Est.** : 3h

---

## 436-5 [B] Créer AiQuotaManager

**Description** : Créer un service qui gère les quotas AI par company et par module.

**Fichiers impactés** :
- `app/Core/Ai/AiQuotaManager.php` (nouveau)

**Critères d'acceptation** :
- [ ] `canProcess(Company $company, string $moduleKey): bool` — vérifie si la company n'a pas dépassé son quota mensuel
- [ ] `usage(Company $company, string $moduleKey): int` — nombre de requests ce mois
- [ ] `limit(Company $company, string $moduleKey): int` — limite depuis le plan de la company
- [ ] `remaining(Company $company, string $moduleKey): int` — limit - usage
- [ ] Le quota est par module et par mois calendaire
- [ ] Le quota par défaut est 100/mois si non défini dans le plan

**Dépendance** : → 436-1
**Est.** : 3h

---

## 436-6 [B] Intégrer le quota dans le pipeline AI

**Description** : Ajouter la vérification de quota avant le dispatching d'un job AI.

**Fichiers impactés** :
- `app/Jobs/Documents/ProcessDocumentAiJob.php` — ajouter quota check
- Ou mieux : dans le controller qui dispatch le job (MemberDocumentController)

**Critères d'acceptation** :
- [ ] Avant de dispatcher un job AI, vérifier `AiQuotaManager::canProcess()`
- [ ] Si quota dépassé, retourner une erreur 429 avec message "AI quota exceeded for this month"
- [ ] L'erreur est i18n-compatible
- [ ] Le quota est vérifié dans le controller, pas dans le job (éviter les jobs dispatchés qui échouent)
- [ ] `php artisan test` passe

**Dépendance** : → 436-5
**Est.** : 2h

---

## 436-7 [F] Composants AI réutilisables

**Description** : Extraire les composants AI du module Documents en composants réutilisables.

**Fichiers impactés** :
- `resources/js/views/shared/ai/AiStatusChip.vue` (nouveau, extrait de DocumentAiChip)
- `resources/js/views/shared/ai/AiInsightPanel.vue` (nouveau)

**Critères d'acceptation** :
- [ ] `AiStatusChip` accepte `aiStatus` (pending/processing/completed/failed), `confidence`, `uploadedAt` (pour timeout UX)
- [ ] `AiInsightPanel` accepte `insights[]` et affiche des VAlert avec severity mapping
- [ ] Les composants existants Documents sont refactorés pour utiliser ces nouveaux composants partagés
- [ ] Aucune régression sur les pages Documents
- [ ] Les composants sont dans `views/shared/ai/` (pas dans un module spécifique)

**Dépendance** : ⊕ 436-4
**Est.** : 4h

---

## 436-8 [B] Endpoint quota usage pour le frontend

**Description** : Créer un endpoint company qui retourne l'usage AI et le quota restant.

**Fichiers impactés** :
- Controller company existant ou nouveau — endpoint GET `/api/ai/quota`

**Critères d'acceptation** :
- [ ] Retourne `{ module_key, used, limit, remaining, period_start, period_end }` par module
- [ ] Protégé par middleware company.context + auth
- [ ] `php artisan test` passe

**Dépendance** : → 436-5
**Est.** : 2h

---

## 436-9 [T] Tests AI extension

**Description** : Tests vérifiant le fonctionnement du registry et du quota.

**Fichiers impactés** :
- `tests/Feature/AiModuleContractTest.php` (nouveau)
- `tests/Feature/AiQuotaTest.php` (nouveau)

**Critères d'acceptation** :
- [ ] Test que le registry enregistre et retourne les modules correctement
- [ ] Test que le PolicyResolver résout via le registry
- [ ] Test que le quota bloque au-delà de la limite
- [ ] Test que Documents fonctionne identiquement via le nouveau pattern
- [ ] `php artisan test` passe

**Dépendance** : → 436-6
**Est.** : 4h

---

## 436 — Ordre d'exécution

```
436-1 (interface)
  → 436-2 (registry)
    → 436-3 (refactor PolicyResolver)
      → 436-4 (migrate Documents)  ⊕ 436-7 (composants frontend)
  → 436-5 (quota manager)
    → 436-6 (quota dans pipeline)
    → 436-8 (endpoint quota)
  → 436-9 (tests)
```

---

# ADR-437 — AUTOMATION : Workflow Engine User-Defined

**Priorité** : P3 PLANIFIÉ
**Effort total** : 12j (96h)
**Pré-requis** : ADR-432 (BelongsToCompany), ADR-434 (SSE pour triggers)

---

## 437-1 [B] Créer le modèle WorkflowRule + migration

**Description** : Créer le modèle company-scoped pour les workflows user-defined.

**Fichiers impactés** :
- `app/Core/Automation/WorkflowRule.php` (nouveau)
- `database/migrations/xxxx_create_workflow_rules_table.php` (nouveau)

**Critères d'acceptation** :
- [ ] Table `workflow_rules` avec : `id`, `company_id` (FK), `name`, `trigger_type` (string), `trigger_config` (JSON), `conditions` (JSON), `actions` (JSON), `enabled` (bool), `max_executions_per_day` (int), `cooldown_minutes` (int), `last_triggered_at`, `execution_count_today` (int), `timestamps`
- [ ] Modèle utilise `BelongsToCompany` trait
- [ ] Modèle utilise JSON casts pour `trigger_config`, `conditions`, `actions`
- [ ] Index sur `(company_id, trigger_type, enabled)` pour lookup rapide
- [ ] Scope `active()` : `enabled = true`
- [ ] Scope `forTrigger(string $type)` : `trigger_type = $type AND enabled = true`

**Est.** : 3h

---

## 437-2 [B] Créer le modèle WorkflowExecutionLog + migration

**Description** : Créer le modèle de logs d'exécution des workflows.

**Fichiers impactés** :
- `app/Core/Automation/WorkflowExecutionLog.php` (nouveau)
- `database/migrations/xxxx_create_workflow_execution_logs_table.php` (nouveau)

**Critères d'acceptation** :
- [ ] Table `workflow_execution_logs` avec : `id`, `workflow_rule_id` (FK cascade), `company_id` (FK), `trigger_payload` (JSON), `conditions_result` (bool), `actions_executed` (JSON), `status` (success/failed/skipped), `error` (text nullable), `duration_ms` (int), `timestamps`
- [ ] Modèle utilise `BelongsToCompany` trait
- [ ] Index sur `(workflow_rule_id, created_at)` et `(company_id, created_at)`
- [ ] Relation `belongsTo(WorkflowRule)`

**Dépendance** : ⊕ 437-1
**Est.** : 2h

---

## 437-3 [B] Créer le TriggerRegistry

**Description** : Créer un registre déclaratif où chaque module enregistre ses triggers disponibles.

**Fichiers impactés** :
- `app/Core/Automation/TriggerRegistry.php` (nouveau)
- `app/Core/Automation/DTOs/TriggerDefinition.php` (nouveau)

**Critères d'acceptation** :
- [ ] `TriggerDefinition` : `key` (string), `label` (string), `moduleKey` (string), `payloadSchema` (array — champs disponibles pour les conditions)
- [ ] `TriggerRegistry::register(TriggerDefinition)` — enregistre un trigger
- [ ] `TriggerRegistry::all()` — retourne tous les triggers
- [ ] `TriggerRegistry::forModule(string $moduleKey)` — filtre par module
- [ ] `TriggerRegistry::get(string $triggerKey)` — retourne un trigger
- [ ] Statique, singleton, initialisé au boot via ServiceProvider

**Dépendance** : → 437-1
**Est.** : 3h

---

## 437-4 [B] Enregistrer les triggers Documents + Members + Billing

**Description** : Chaque module déclare ses triggers dans son ServiceProvider.

**Fichiers impactés** :
- Service provider ou module manifest pour Documents, Members, Billing

**Critères d'acceptation** :
- [ ] Documents : `document.uploaded` (payload: document_type, member_id), `document.ai_completed` (payload: ai_status, confidence), `document.expiring` (payload: days_until_expiry)
- [ ] Members : `member.joined` (payload: role, email), `member.removed` (payload: role)
- [ ] Billing : `invoice.created` (payload: amount, currency), `payment.failed` (payload: amount, reason), `subscription.expiring` (payload: days_until_expiry)
- [ ] Tous les triggers ont un `payloadSchema` complet
- [ ] `TriggerRegistry::all()` retourne au moins 8 triggers

**Dépendance** : → 437-3
**Est.** : 3h

---

## 437-5 [B] Créer le ConditionEvaluator

**Description** : Créer le service qui évalue des conditions JSON contre un contexte de données.

**Fichiers impactés** :
- `app/Core/Automation/ConditionEvaluator.php` (nouveau)

**Critères d'acceptation** :
- [ ] `evaluate(array $conditions, array $context): bool` — retourne true si toutes les conditions passent
- [ ] Opérateurs supportés : `eq`, `neq`, `gt`, `gte`, `lt`, `lte`, `in`, `not_in`, `contains`, `is_null`, `is_not_null`
- [ ] Le `field` utilise dot notation (`data_get()`) pour accéder au contexte
- [ ] Un array `conditions` vide retourne `true` (pas de condition = toujours exécuter)
- [ ] Les erreurs de type (comparer string à int) ne crashent pas — retournent false

**Dépendance** : → 437-3
**Est.** : 3h

---

## 437-6 [B] Créer le ActionExecutor + actions de base

**Description** : Créer le service qui exécute des actions et les premières actions concrètes.

**Fichiers impactés** :
- `app/Core/Automation/ActionExecutor.php` (nouveau)
- `app/Core/Automation/Actions/SendNotificationAction.php` (nouveau)
- `app/Core/Automation/Actions/SendEmailAction.php` (nouveau)
- `app/Core/Automation/Actions/WebhookAction.php` (nouveau)
- `app/Core/Automation/Contracts/WorkflowAction.php` (nouveau interface)

**Critères d'acceptation** :
- [ ] Interface `WorkflowAction` : `execute(WorkflowRule $rule, array $context): array` retourne `['status' => 'success', ...]`
- [ ] `ActionExecutor::execute(array $actions, WorkflowRule $rule, array $context): array` — exécute chaque action séquentiellement, retourne les résultats
- [ ] `SendNotificationAction` : envoie une notification in-app via NotificationDispatcher
- [ ] `SendEmailAction` : envoie un email via le mailer Laravel
- [ ] `WebhookAction` : POST vers une URL avec le payload (timeout 10s, retry 0)
- [ ] Chaque action est enregistrée dans un registre statique par clé

**Dépendance** : → 437-5
**Est.** : 5h

---

## 437-7 [B] Créer le ProcessWorkflowJob

**Description** : Créer le job async qui évalue les conditions et exécute les actions d'un workflow.

**Fichiers impactés** :
- `app/Core/Automation/Jobs/ProcessWorkflowJob.php` (nouveau)

**Critères d'acceptation** :
- [ ] Queue `default`, timeout 60s, tries 1
- [ ] Reçoit `WorkflowRule $rule` et `array $payload`
- [ ] Vérifie cooldown (`last_triggered_at + cooldown_minutes > now()` → skip)
- [ ] Vérifie quota jour (`execution_count_today >= max_executions_per_day` → skip)
- [ ] Évalue conditions via `ConditionEvaluator::evaluate($rule->conditions, $payload)`
- [ ] Si conditions passent : exécute actions via `ActionExecutor::execute()`
- [ ] Crée un `WorkflowExecutionLog` avec le résultat
- [ ] Met à jour `last_triggered_at` et `execution_count_today` sur la rule
- [ ] En cas d'erreur : log status=failed, ne crash pas le job

**Dépendance** : → 437-5, 437-6
**Est.** : 4h

---

## 437-8 [B] Hook dans EventEnvelope pour déclencher les triggers

**Description** : Après chaque publication d'un domain event, évaluer s'il existe des workflows à déclencher.

**Fichiers impactés** :
- `app/Core/Realtime/PublishesRealtimeEvents.php` (le trait utilisé par les controllers)
- Ou `app/Core/Realtime/EventEnvelope.php` — méthode statique de publication

**Critères d'acceptation** :
- [ ] Après la publication d'un domain event avec `company_id` non null, appeler `TriggerRegistry::evaluate($topic, $payload, $companyId)`
- [ ] `TriggerRegistry::evaluate()` charge les WorkflowRules actives pour ce trigger_type et cette company, et dispatch un `ProcessWorkflowJob` pour chacune
- [ ] Le hook est asynchrone (dispatch job, pas d'exécution synchrone)
- [ ] Le hook ne ralentit pas la publication du domain event (fire-and-forget dispatch)
- [ ] Si pas de workflows pour ce trigger : no-op (pas de query DB si aucun trigger enregistré pour ce topic)
- [ ] `php artisan test` passe

**Dépendance** : → 437-7
**Est.** : 4h

---

## 437-9 [B] API CRUD workflows + quota enforcement

**Description** : Créer les endpoints pour gérer les workflows d'une company.

**Fichiers impactés** :
- `app/Modules/Core/Automations/Http/WorkflowController.php` (nouveau)
- `routes/company.php` — ajouter les routes

**Critères d'acceptation** :
- [ ] `GET /workflows` — liste les workflows de la company (paginé)
- [ ] `GET /workflows/{id}` — détail d'un workflow
- [ ] `POST /workflows` — créer un workflow (validation du trigger_type, conditions, actions)
- [ ] `PUT /workflows/{id}` — modifier un workflow
- [ ] `DELETE /workflows/{id}` — supprimer un workflow
- [ ] `GET /workflows/{id}/logs` — historique d'exécution (paginé)
- [ ] `GET /workflows/triggers` — catalogue des triggers disponibles (depuis TriggerRegistry)
- [ ] `GET /workflows/actions` — catalogue des actions disponibles
- [ ] Quota enforcement : le nombre de workflows actifs par company est limité par le plan
- [ ] Middleware : `company.access:use-permission,automations.manage`
- [ ] `php artisan test` passe

**Dépendance** : → 437-8
**Est.** : 6h

---

## 437-10 [B] Ajouter la permission automations.manage

**Description** : Ajouter la permission company-scope pour gérer les automations.

**Fichiers impactés** :
- Créer un nouveau module company ou ajouter la permission à un module core existant
- La seed des permissions (CompanyPermissionCatalog sync)

**Critères d'acceptation** :
- [ ] Permission `automations.manage` existe dans le catalog company
- [ ] La permission est gatable via middleware
- [ ] La navigation company montre "Automations" pour les utilisateurs avec cette permission
- [ ] `php artisan test` passe

**Dépendance** : ⊕ 437-9
**Est.** : 2h

---

## 437-11 [F] Page Workflows — liste + CRUD

**Description** : Créer la page company pour lister et gérer les workflows.

**Fichiers impactés** :
- `resources/js/pages/company/automations/index.vue` (nouveau)
- `resources/js/modules/company/automations/automations.store.js` (nouveau)

**Critères d'acceptation** :
- [ ] VDataTableServer avec les workflows de la company
- [ ] Colonnes : name, trigger_type, enabled (toggle), last_triggered_at, execution_count_today, actions
- [ ] Bouton "Créer un workflow" ouvre un drawer
- [ ] Toggle enable/disable en inline
- [ ] Bouton supprimer avec confirmation
- [ ] Empty state avec message "Aucune automation configurée"
- [ ] `definePage({ meta: { permission: 'automations.manage' } })`
- [ ] Utilise les presets Vuexy existants (tables, drawers)

**Dépendance** : → 437-9
**Est.** : 8h

---

## 437-12 [F] Drawer workflow builder

**Description** : Créer le drawer de création/édition de workflow avec sélection trigger → conditions → actions.

**Fichiers impactés** :
- `resources/js/pages/company/automations/_WorkflowDrawer.vue` (nouveau)

**Critères d'acceptation** :
- [ ] Step 1 : Sélection du trigger (dropdown avec les triggers du TriggerRegistry, groupés par module)
- [ ] Step 2 : Configuration des conditions (formulaire dynamique basé sur le payloadSchema du trigger)
- [ ] Step 3 : Sélection et configuration des actions (multi-select avec config par action)
- [ ] Step 4 : Nom, quota max/jour, cooldown
- [ ] Bouton "Sauvegarder"
- [ ] Validation frontend des champs requis
- [ ] Utilise les presets Vuexy wizards ou forms

**Dépendance** : → 437-11
**Est.** : 10h

---

## 437-13 [F] Page exécution history

**Description** : Afficher l'historique d'exécution d'un workflow (logs).

**Fichiers impactés** :
- `resources/js/pages/company/automations/_WorkflowLogs.vue` (nouveau, sous-composant dans le drawer)

**Critères d'acceptation** :
- [ ] VDataTable avec les logs : date, status (chip coloré), duration_ms, trigger_payload (expandable), actions_executed
- [ ] Pagination
- [ ] Filtre par status (success/failed/skipped)
- [ ] Accessible depuis le drawer du workflow (onglet "Historique")

**Dépendance** : → 437-11
**Est.** : 4h

---

## 437-14 [T] Tests workflow engine

**Description** : Tests complets du workflow engine.

**Fichiers impactés** :
- `tests/Feature/WorkflowEngineTest.php` (nouveau)
- `tests/Feature/ConditionEvaluatorTest.php` (nouveau)

**Critères d'acceptation** :
- [ ] Test ConditionEvaluator avec tous les opérateurs
- [ ] Test ProcessWorkflowJob : trigger → conditions → actions → log
- [ ] Test cooldown : workflow ne se déclenche pas pendant le cooldown
- [ ] Test quota jour : workflow skip au-delà du max
- [ ] Test isolation : workflow d'une company ne se déclenche pas pour une autre
- [ ] Test API CRUD : create, read, update, delete workflow
- [ ] `php artisan test` passe

**Dépendance** : → 437-8
**Est.** : 6h

---

## 437 — Ordre d'exécution

```
437-1 (WorkflowRule)     ⊕ 437-2 (ExecutionLog)
  → 437-3 (TriggerRegistry)
    → 437-4 (enregistrer triggers)  ⊕ 437-5 (ConditionEvaluator)
                                      → 437-6 (ActionExecutor)
                                        → 437-7 (ProcessWorkflowJob)
                                          → 437-8 (hook EventEnvelope)
                                            → 437-9 (API CRUD)  ⊕ 437-10 (permission)
                                              → 437-11 (page liste)
                                                → 437-12 (workflow builder) ⊕ 437-13 (logs)
                                          → 437-14 (tests)
```

---

# RÉSUMÉ GLOBAL

## Effort par ADR

| ADR | Scope | Tâches | Effort | Priorité |
|-----|-------|--------|--------|----------|
| **432** | Tenancy | 8 | 4.5j (36h) | P0 CRITIQUE |
| **433** | RBAC Frontend | 10 | 4.5j (36h) | P1 URGENT |
| **434** | SSE Realtime | 9 | 6j (48h) | P1 URGENT |
| **435** | Multi-Market | 7 | 4.5j (36h) | P2 REQUIS |
| **436** | AI Engine | 9 | 7j (56h) | P2 REQUIS |
| **437** | Automation | 14 | 12j (96h) | P3 PLANIFIÉ |
| **TOTAL** | | **57** | **38.5j (308h)** | |

## Ordre de livraison recommandé

```
Sprint V2-0   ADR-432 (Tenancy)                    4.5j
Sprint V2-1   ADR-433 (RBAC) ⊕ ADR-435 (Market)    ~6j (parallèle)
Sprint V2-2   ADR-434 (Realtime)                    6j
Sprint V2-3   ADR-436 (AI Engine)                   7j
Sprint V2-4   ADR-437 (Automation)                  12j
```

## Dépendances inter-ADR

```
ADR-432 (Tenancy)
  → ADR-436 (AI — AiRequestLog nullable)
  → ADR-437 (Automation — WorkflowRule BelongsToCompany)

ADR-433 (RBAC)
  → ADR-434 (Realtime — rbac.changed handler)

ADR-434 (Realtime) — dépend de ADR-433 pour le refresh permissions

ADR-435 (Market) — indépendant, parallélisable

ADR-436 (AI) — dépend de ADR-432 pour le trait

ADR-437 (Automation) — dépend de ADR-432 + ADR-434
```
