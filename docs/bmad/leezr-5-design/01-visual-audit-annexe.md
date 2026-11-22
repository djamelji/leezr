# Annexe — mesures par page (exploration Leezr 5.0, clone marqué, 2026-11-22)

Exploration automatique en lecture seule (`tests/e2e/audit842-crawl.spec.js`), profils super-admin Platform et propriétaire Company (société 1). Captures : `captures/current/shots/<profil>/<page>.jpg`.

Colonnes : **Titre** = premier titre de la page · **Ong.** = onglets · **Act.** = boutons visibles · **Al.** = bandeaux d'alerte · **Tab.** = tableaux · **Vide** = état vide détecté · **Icônes s.l.** = boutons-icônes sans libellé accessible · **Axe** = violations graves/critiques.

## Platform — super-admin — 72 pages

| Page | Titre | Ong. | Act. | Al. | Tab. | Vide | Icônes s.l. | Axe | Capture |
|---|---|---|---|---|---|---|---|---|---|
| `/platform/access/logs` | — | 3 | 3 | 0 | 1 |  | 29 | 0 | [jpg](captures/current/shots/p-superadmin/platform_access_logs.jpg) |
| `/platform/access/roles` | Super Admin | 3 | 4 | 0 | 0 | oui | 28 | 0 | [jpg](captures/current/shots/p-superadmin/platform_access_roles.jpg) |
| `/platform/access/users` | — | 3 | 4 | 0 | 1 |  | 4 | 3 | [jpg](captures/current/shots/p-superadmin/platform_access_users.jpg) |
| `/platform/billing/consolidated-preview` | — | 0 | 0 | 1 | 2 | oui | 4 | 0 | [jpg](captures/current/shots/p-superadmin/platform_billing_consolidated_preview_company_106.jpg) |
| `/platform/billing/credit-notes` | Aperçu facturation | 11 | 12 | 0 | 1 |  | 4 | 0 | [jpg](captures/current/shots/p-superadmin/platform_billing_credit_notes.jpg) |
| `/platform/billing/dunning` | Aperçu facturation | 11 | 12 | 1 | 1 |  | 8 | 0 | [jpg](captures/current/shots/p-superadmin/platform_billing_dunning.jpg) |
| `/platform/billing/forensics` | Aperçu facturation | 10 | 12 | 1 | 0 |  | 4 | 0 | [jpg](captures/current/shots/p-superadmin/platform_billing_forensics.jpg) |
| `/platform/billing/governance` | Aperçu facturation | 10 | 12 | 0 | 0 |  | 4 | 0 | [jpg](captures/current/shots/p-superadmin/platform_billing_governance.jpg) |
| `/platform/billing/invoices` | Aperçu facturation | 11 | 13 | 1 | 1 |  | 24 | 0 | [jpg](captures/current/shots/p-superadmin/platform_billing_invoices.jpg) |
| `/platform/billing/invoices/:id` | — | 0 | 2 | 0 | 5 | oui | 4 | 0 | [jpg](captures/current/shots/p-superadmin/platform_billing_invoices_119.jpg) |
| `/platform/billing/ledger` | Aperçu facturation | 10 | 11 | 0 | 0 |  | 4 | 0 | [jpg](captures/current/shots/p-superadmin/platform_billing_ledger.jpg) |
| `/platform/billing/overview` | Aperçu facturation | 5 | 10 | 4 | 0 |  | 5 | 4 | [jpg](captures/current/shots/p-superadmin/platform_billing_overview.jpg) |
| `/platform/billing/payments` | Aperçu facturation | 11 | 12 | 0 | 1 |  | 4 | 0 | [jpg](captures/current/shots/p-superadmin/platform_billing_payments.jpg) |
| `/platform/billing/reconciliation` | Aperçu facturation | 10 | 11 | 1 | 3 | oui | 4 | 0 | [jpg](captures/current/shots/p-superadmin/platform_billing_reconciliation.jpg) |
| `/platform/billing/recovery` | Aperçu facturation | 10 | 17 | 2 | 0 | oui | 4 | 0 | [jpg](captures/current/shots/p-superadmin/platform_billing_recovery.jpg) |
| `/platform/billing/scheduled-debits` | Aperçu facturation | 11 | 12 | 1 | 0 | oui | 4 | 0 | [jpg](captures/current/shots/p-superadmin/platform_billing_scheduled_debits.jpg) |
| `/platform/billing/subscriptions` | Aperçu facturation | 7 | 10 | 1 | 1 |  | 18 | 0 | [jpg](captures/current/shots/p-superadmin/platform_billing_subscriptions.jpg) |
| `/platform/billing/tariff-review` | Aperçu facturation | 7 | 13 | 1 | 1 |  | 4 | 0 | [jpg](captures/current/shots/p-superadmin/platform_billing_tariff_review.jpg) |
| `/platform/billing/wallets` | Aperçu facturation | 11 | 12 | 0 | 1 |  | 4 | 0 | [jpg](captures/current/shots/p-superadmin/platform_billing_wallets.jpg) |
| `/platform/catalog/documents` | — | 9 | 25 | 0 | 1 |  | 18 | 0 | [jpg](captures/current/shots/p-superadmin/platform_catalog_documents.jpg) |
| `/platform/catalog/fields` | — | 8 | 9 | 0 | 1 |  | 50 | 0 | [jpg](captures/current/shots/p-superadmin/platform_catalog_fields.jpg) |
| `/platform/catalog/jobdomains` | — | 6 | 9 | 0 | 1 |  | 6 | 0 | [jpg](captures/current/shots/p-superadmin/platform_catalog_jobdomains.jpg) |
| `/platform/catalog/modules` | — | 8 | 9 | 0 | 1 |  | 4 | 3 | [jpg](captures/current/shots/p-superadmin/platform_catalog_modules.jpg) |
| `/platform/catalog/plans` | Abonnements | 6 | 10 | 0 | 0 |  | 4 | 0 | [jpg](captures/current/shots/p-superadmin/platform_catalog_plans.jpg) |
| `/platform/catalog/presets` | — | 6 | 7 | 1 | 1 | oui | 4 | 0 | [jpg](captures/current/shots/p-superadmin/platform_catalog_presets.jpg) |
| `/platform/communications/documentation` | Communications | 6 | 9 | 0 | 1 | oui | 26 | 0 | [jpg](captures/current/shots/p-superadmin/platform_communications_documentation.jpg) |
| `/platform/communications/support` | Communications | 2 | 2 | 0 | 1 |  | 4 | 3 | [jpg](captures/current/shots/p-superadmin/platform_communications_support.jpg) |
| `/platform/companies` | 26 | 3 | 4 | 0 | 1 |  | 4 | 3 | [jpg](captures/current/shots/p-superadmin/platform_companies.jpg) |
| `/platform/companies/:id` | Promo promo.valide.1795304708827@audit84 | 7 | 10 | 0 | 0 |  | 4 | 0 | [jpg](captures/current/shots/p-superadmin/platform_companies_108.jpg) |
| `/platform/companies/:id/billing` | Promo promo.valide.1795304708827@audit84 | 7 | 12 | 1 | 0 | oui | 8 | 0 | [jpg](captures/current/shots/p-superadmin/platform_companies_108_billing.jpg) |
| `/platform/companies/create` | Nouvelle société | 0 | 3 | 0 | 0 |  | 4 | 0 | [jpg](captures/current/shots/p-superadmin/platform_companies_create.jpg) |
| `/platform/contract-templates` | Modèles de contrat | 0 | 6 | 1 | 1 |  | 11 | 4 | [jpg](captures/current/shots/p-superadmin/platform_contract_templates.jpg) |
| `/platform/dashboard/activity` | Activité | 3 | 4 | 0 | 0 |  | 5 | 0 | [jpg](captures/current/shots/p-superadmin/platform_dashboard_activity.jpg) |
| `/platform/dashboard/funnel` | Parcours d'inscription | 3 | 4 | 0 | 1 | oui | 4 | 0 | [jpg](captures/current/shots/p-superadmin/platform_dashboard_funnel.jpg) |
| `/platform/dashboard/overview` | Cockpit | 3 | 9 | 0 | 0 |  | 4 | 4 | [jpg](captures/current/shots/p-superadmin/platform_dashboard_overview.jpg) |
| `/platform/documents/:id` | — | 0 | 3 | 0 | 0 |  | 4 | 0 | [jpg](captures/current/shots/p-superadmin/platform_documents_13.jpg) |
| `/platform/jobdomains/:id` | Logistique | 4 | 7 | 0 | 0 | oui | 4 | 0 | [jpg](captures/current/shots/p-superadmin/platform_jobdomains_1.jpg) |
| `/platform/jobdomains/:id/fields` | Logistique | 4 | 4 | 0 | 1 |  | 4 | 0 | [jpg](captures/current/shots/p-superadmin/platform_jobdomains_1_fields.jpg) |
| `/platform/jobdomains/:id/general` | Logistique | 4 | 7 | 0 | 0 | oui | 4 | 0 | [jpg](captures/current/shots/p-superadmin/platform_jobdomains_1_general.jpg) |
| `/platform/jobdomains/:id/modules` | Logistique | 4 | 5 | 0 | 1 |  | 4 | 0 | [jpg](captures/current/shots/p-superadmin/platform_jobdomains_1_modules.jpg) |
| `/platform/jobdomains/:id/setup` | Logistique | 4 | 6 | 0 | 0 | oui | 4 | 0 | [jpg](captures/current/shots/p-superadmin/platform_jobdomains_1_setup.jpg) |
| `/platform/messaging/health` | Messagerie | 7 | 7 | 0 | 0 |  | 4 | 0 | [jpg](captures/current/shots/p-superadmin/platform_messaging_health.jpg) |
| `/platform/messaging/inbox` | Messagerie | 7 | 8 | 0 | 0 |  | 31 | 3 | [jpg](captures/current/shots/p-superadmin/platform_messaging_inbox.jpg) |
| `/platform/messaging/logs` | Messagerie | 7 | 7 | 0 | 1 |  | 24 | 0 | [jpg](captures/current/shots/p-superadmin/platform_messaging_logs.jpg) |
| `/platform/messaging/notifications` | Messagerie | 7 | 8 | 0 | 0 |  | 9 | 0 | [jpg](captures/current/shots/p-superadmin/platform_messaging_notifications.jpg) |
| `/platform/messaging/orchestration` | Messagerie | 7 | 9 | 1 | 0 | oui | 4 | 0 | [jpg](captures/current/shots/p-superadmin/platform_messaging_orchestration.jpg) |
| `/platform/messaging/settings` | Messagerie | 7 | 13 | 3 | 0 |  | 4 | 0 | [jpg](captures/current/shots/p-superadmin/platform_messaging_settings.jpg) |
| `/platform/messaging/templates` | Messagerie | 7 | 10 | 0 | 0 | oui | 5 | 0 | [jpg](captures/current/shots/p-superadmin/platform_messaging_templates.jpg) |
| `/platform/modules/:key/activity` | Dashboard | 5 | 6 | 0 | 0 | oui | 5 | 0 | [jpg](captures/current/shots/p-superadmin/platform_modules_core_dashboard_activity.jpg) |
| `/platform/modules/:key/advanced` | Dashboard | 5 | 6 | 0 | 0 |  | 5 | 0 | [jpg](captures/current/shots/p-superadmin/platform_modules_core_dashboard_advanced.jpg) |
| `/platform/modules/:key/commercial` | Dashboard | 5 | 6 | 1 | 0 |  | 5 | 0 | [jpg](captures/current/shots/p-superadmin/platform_modules_core_dashboard_commercial.jpg) |
| `/platform/modules/:key/companies` | Dashboard | 5 | 5 | 0 | 0 |  | 5 | 0 | [jpg](captures/current/shots/p-superadmin/platform_modules_core_dashboard_companies.jpg) |
| `/platform/modules/:key/features` | Dashboard | 5 | 5 | 0 | 0 | oui | 5 | 0 | [jpg](captures/current/shots/p-superadmin/platform_modules_core_dashboard_features.jpg) |
| `/platform/modules/:key/members` | Dashboard | 5 | 6 | 0 | 0 | oui | 5 | 0 | [jpg](captures/current/shots/p-superadmin/platform_modules_core_dashboard_members.jpg) |
| `/platform/modules/:key/modules` | Dashboard | 5 | 6 | 0 | 0 | oui | 5 | 0 | [jpg](captures/current/shots/p-superadmin/platform_modules_core_dashboard_modules.jpg) |
| `/platform/operations/ai` | Configurer | 11 | 20 | 1 | 0 | oui | 5 | 0 | [jpg](captures/current/shots/p-superadmin/platform_operations_ai.jpg) |
| `/platform/operations/alerts` | Alertes | 7 | 8 | 0 | 1 | oui | 4 | 0 | [jpg](captures/current/shots/p-superadmin/platform_operations_alerts.jpg) |
| `/platform/operations/automations` | Centre d'automatisation | 7 | 8 | 0 | 1 |  | 24 | 0 | [jpg](captures/current/shots/p-superadmin/platform_operations_automations.jpg) |
| `/platform/operations/health` | Santé du système | 7 | 8 | 1 | 0 |  | 4 | 3 | [jpg](captures/current/shots/p-superadmin/platform_operations_health.jpg) |
| `/platform/operations/realtime` | Backbone Temps Réel | 7 | 9 | 0 | 2 | oui | 4 | 0 | [jpg](captures/current/shots/p-superadmin/platform_operations_realtime.jpg) |
| `/platform/operations/security` | — | 7 | 19 | 0 | 1 |  | 8 | 0 | [jpg](captures/current/shots/p-superadmin/platform_operations_security.jpg) |
| `/platform/operations/usage` | Utilisation & Consommation | 7 | 13 | 0 | 0 | oui | 4 | 0 | [jpg](captures/current/shots/p-superadmin/platform_operations_usage.jpg) |
| `/platform/organization-presets` | Presets d'organisation | 0 | 1 | 0 | 1 |  | 4 | 0 | [jpg](captures/current/shots/p-superadmin/platform_organization_presets.jpg) |
| `/platform/settings/billing` | — | 8 | 8 | 2 | 0 |  | 4 | 0 | [jpg](captures/current/shots/p-superadmin/platform_settings_billing.jpg) |
| `/platform/settings/feature-flags` | Feature Flags | 8 | 9 | 0 | 1 | oui | 4 | 0 | [jpg](captures/current/shots/p-superadmin/platform_settings_feature_flags.jpg) |
| `/platform/settings/general` | — | 8 | 8 | 0 | 0 |  | 4 | 3 | [jpg](captures/current/shots/p-superadmin/platform_settings_general.jpg) |
| `/platform/settings/international` | International | 12 | 15 | 0 | 1 |  | 6 | 0 | [jpg](captures/current/shots/p-superadmin/platform_settings_international.jpg) |
| `/platform/settings/maintenance` | We'll be back soon! | 8 | 12 | 0 | 0 |  | 4 | 0 | [jpg](captures/current/shots/p-superadmin/platform_settings_maintenance.jpg) |
| `/platform/settings/notifications` | — | 8 | 8 | 0 | 1 |  | 4 | 0 | [jpg](captures/current/shots/p-superadmin/platform_settings_notifications.jpg) |
| `/platform/settings/sessions` | — | 8 | 10 | 1 | 0 |  | 4 | 0 | [jpg](captures/current/shots/p-superadmin/platform_settings_sessions.jpg) |
| `/platform/settings/theme` | Heading 1 | 8 | 12 | 1 | 0 |  | 4 | 0 | [jpg](captures/current/shots/p-superadmin/platform_settings_theme.jpg) |
| `/platform/users/:id` | Double facteur Recette M2 | 3 | 4 | 0 | 0 |  | 5 | 0 | [jpg](captures/current/shots/p-superadmin/platform_users_27.jpg) |

## Company — propriétaire (société 1, Logistique) — 56 pages

| Page | Titre | Ong. | Act. | Al. | Tab. | Vide | Icônes s.l. | Axe | Capture |
|---|---|---|---|---|---|---|---|---|---|
| `/account-settings/account` | — | 2 | 5 | 2 | 0 |  | 0 | 0 | [jpg](captures/current/shots/c1-owner/account_settings_account.jpg) |
| `/account-settings/security` | — | 2 | 4 | 0 | 0 |  | 0 | 0 | [jpg](captures/current/shots/c1-owner/account_settings_security.jpg) |
| `/company/audit` | — | 0 | 0 | 0 | 1 |  | 25 | 1 | [jpg](captures/current/shots/c1-owner/company_audit.jpg) |
| `/company/billing/activity` | Abonnement & factures | 6 | 6 | 0 | 0 | oui | 0 | 0 | [jpg](captures/current/shots/c1-owner/company_billing_activity.jpg) |
| `/company/billing/closure` | Abonnement & factures | 6 | 8 | 0 | 0 | oui | 0 | 0 | [jpg](captures/current/shots/c1-owner/company_billing_closure.jpg) |
| `/company/billing/consolidated-preview` | — | 0 | 1 | 1 | 4 | oui | 0 | 0 | [jpg](captures/current/shots/c1-owner/company_billing_consolidated_preview.jpg) |
| `/company/billing/invoices` | Abonnement & factures | 6 | 7 | 0 | 1 |  | 0 | 0 | [jpg](captures/current/shots/c1-owner/company_billing_invoices.jpg) |
| `/company/billing/invoices/:id` | — | 0 | 4 | 0 | 7 | oui | 0 | 0 | [jpg](captures/current/shots/c1-owner/company_billing_invoices_61.jpg) |
| `/company/billing/overview` | Abonnement & factures | 6 | 11 | 1 | 0 |  | 0 | 1 | [jpg](captures/current/shots/c1-owner/company_billing_overview.jpg) |
| `/company/billing/payment-methods` | Abonnement & factures | 6 | 6 | 0 | 0 |  | 1 | 0 | [jpg](captures/current/shots/c1-owner/company_billing_payment_methods.jpg) |
| `/company/billing/plan` | Abonnement & factures | 6 | 9 | 1 | 0 | oui | 0 | 0 | [jpg](captures/current/shots/c1-owner/company_billing_plan.jpg) |
| `/company/catalogs/fields` | Catalogues | 2 | 3 | 1 | 1 |  | 0 | 0 | [jpg](captures/current/shots/c1-owner/company_catalogs_fields.jpg) |
| `/company/documents/company` | Documents | 4 | 14 | 1 | 1 | oui | 2 | 0 | [jpg](captures/current/shots/c1-owner/company_documents_company_new_deposit.jpg) |
| `/company/documents/compliance` | Documents | 0 | 7 | 1 | 1 |  | 1 | 0 | [jpg](captures/current/shots/c1-owner/company_documents_compliance_status_missing.jpg) |
| `/company/documents/people` | Documents | 4 | 13 | 1 | 1 |  | 2 | 0 | [jpg](captures/current/shots/c1-owner/company_documents_people_new_request.jpg) |
| `/company/documents/settings` | Documents | 0 | 10 | 2 | 2 | oui | 2 | 5 | [jpg](captures/current/shots/c1-owner/company_documents_settings.jpg) |
| `/company/documents/signatures` | Documents | 10 | 16 | 1 | 1 |  | 9 | 0 | [jpg](captures/current/shots/c1-owner/company_documents_signatures_status_pending.jpg) |
| `/company/documents/templates` | Documents | 0 | 20 | 2 | 1 |  | 9 | 2 | [jpg](captures/current/shots/c1-owner/company_documents_templates.jpg) |
| `/company/documents/todo` | Documents | 4 | 9 | 1 | 0 |  | 1 | 1 | [jpg](captures/current/shots/c1-owner/company_documents_todo.jpg) |
| `/company/modules` | Modules | 0 | 30 | 0 | 0 |  | 0 | 2 | [jpg](captures/current/shots/c1-owner/company_modules.jpg) |
| `/company/modules/:key` | Absences et congés | 0 | 1 | 0 | 0 | oui | 1 | 0 | [jpg](captures/current/shots/c1-owner/company_modules_workforce_leave.jpg) |
| `/company/my-deliveries` | Mes livraisons | 0 | 0 | 0 | 0 | oui | 0 | 0 | [jpg](captures/current/shots/c1-owner/company_my_deliveries.jpg) |
| `/company/my-space/documents` | Mon espace | 4 | 8 | 0 | 1 |  | 3 | 0 | [jpg](captures/current/shots/c1-owner/company_my_space_documents.jpg) |
| `/company/my-space/overview` | Mon espace | 4 | 4 | 0 | 0 |  | 0 | 0 | [jpg](captures/current/shots/c1-owner/company_my_space_overview.jpg) |
| `/company/my-space/payslips` | Mon espace | 4 | 6 | 0 | 0 |  | 0 | 0 | [jpg](captures/current/shots/c1-owner/company_my_space_payslips.jpg) |
| `/company/my-space/signatures` | Mon espace | 4 | 4 | 0 | 0 | oui | 0 | 0 | [jpg](captures/current/shots/c1-owner/company_my_space_signatures.jpg) |
| `/company/people` | Équipe | 5 | 10 | 1 | 1 | oui | 2 | 1 | [jpg](captures/current/shots/c1-owner/company_people.jpg) |
| `/company/people/roles` | Équipe | 7 | 9 | 0 | 0 | oui | 2 | 1 | [jpg](captures/current/shots/c1-owner/company_people_roles.jpg) |
| `/company/plan` | Abonnement & factures | 6 | 9 | 1 | 0 | oui | 0 | 0 | [jpg](captures/current/shots/c1-owner/company_plan.jpg) |
| `/company/profile/overview` | Profil de l'entreprise | 0 | 2 | 1 | 0 |  | 0 | 1 | [jpg](captures/current/shots/c1-owner/company_profile_overview.jpg) |
| `/company/settings` | Réglages | 0 | 0 | 0 | 0 |  | 0 | 2 | [jpg](captures/current/shots/c1-owner/company_settings.jpg) |
| `/company/shipments` | Expéditions | 0 | 2 | 0 | 1 |  | 0 | 0 | [jpg](captures/current/shots/c1-owner/company_shipments.jpg) |
| `/company/shipments/:id` | SHP-20261114-0001 | 0 | 4 | 0 | 0 |  | 1 | 0 | [jpg](captures/current/shots/c1-owner/company_shipments_5.jpg) |
| `/company/shipments/create` | — | 0 | 2 | 0 | 0 |  | 0 | 0 | [jpg](captures/current/shots/c1-owner/company_shipments_create.jpg) |
| `/company/support` | — | 0 | 1 | 0 | 1 |  | 0 | 0 | [jpg](captures/current/shots/c1-owner/company_support.jpg) |
| `/company/workflows` | Aucune règle d'automatisation | 0 | 2 | 0 | 1 | oui | 0 | 0 | [jpg](captures/current/shots/c1-owner/company_workflows.jpg) |
| `/company/workforce/contracts` | Contrats de travail | 0 | 1 | 0 | 1 |  | 0 | 0 | [jpg](captures/current/shots/c1-owner/company_workforce_contracts.jpg) |
| `/company/workforce/hiring` | Embauches | 0 | 3 | 0 | 1 |  | 0 | 1 | [jpg](captures/current/shots/c1-owner/company_workforce_hiring.jpg) |
| `/company/workforce/hiring/:id` | test test | 2 | 5 | 0 | 0 |  | 0 | 0 | [jpg](captures/current/shots/c1-owner/company_workforce_hiring_10.jpg) |
| `/company/workforce/leave` | Planning & temps | 8 | 19 | 0 | 1 |  | 2 | 2 | [jpg](captures/current/shots/c1-owner/company_workforce_leave.jpg) |
| `/company/workforce/leave/config` | Planning & temps | 8 | 16 | 0 | 1 |  | 14 | 2 | [jpg](captures/current/shots/c1-owner/company_workforce_leave_config.jpg) |
| `/company/workforce/leave/timesheets` | Planning & temps | 8 | 19 | 0 | 1 |  | 2 | 0 | [jpg](captures/current/shots/c1-owner/company_workforce_leave_timesheets.jpg) |
| `/company/workforce/me` | Mon activité | 4 | 14 | 1 | 0 | oui | 1 | 2 | [jpg](captures/current/shots/c1-owner/company_workforce_me.jpg) |
| `/company/workforce/onboarding` | Assistant de configuration RH | 0 | 4 | 0 | 0 |  | 0 | 0 | [jpg](captures/current/shots/c1-owner/company_workforce_onboarding.jpg) |
| `/company/workforce/organization/departments` | Organisation | 3 | 10 | 0 | 1 |  | 10 | 1 | [jpg](captures/current/shots/c1-owner/company_workforce_organization_departments.jpg) |
| `/company/workforce/organization/positions` | Organisation | 3 | 10 | 0 | 1 |  | 10 | 0 | [jpg](captures/current/shots/c1-owner/company_workforce_organization_positions.jpg) |
| `/company/workforce/organization/positions/:id` | Gestionnaire administratif | 5 | 8 | 1 | 0 | oui | 0 | 0 | [jpg](captures/current/shots/c1-owner/company_workforce_organization_positions_4.jpg) |
| `/company/workforce/payroll` | Paie & déclarations | 2 | 6 | 1 | 1 | oui | 1 | 3 | [jpg](captures/current/shots/c1-owner/company_workforce_payroll.jpg) |
| `/company/workforce/planning` | Planning & temps | 4 | 30 | 3 | 1 | oui | 10 | 2 | [jpg](captures/current/shots/c1-owner/company_workforce_planning.jpg) |
| `/company/workforce/requirements` | Informations & documents demandés | 0 | 17 | 0 | 0 |  | 0 | 0 | [jpg](captures/current/shots/c1-owner/company_workforce_requirements.jpg) |
| `/company/workforce/settings/general` | Paramètres RH | 4 | 6 | 0 | 0 |  | 0 | 0 | [jpg](captures/current/shots/c1-owner/company_workforce_settings_general.jpg) |
| `/company/workforce/settings/signature` | Paramètres RH | 4 | 5 | 2 | 0 | oui | 0 | 0 | [jpg](captures/current/shots/c1-owner/company_workforce_settings_signature.jpg) |
| `/company/workforce/time` | Planning & temps | 7 | 13 | 0 | 1 | oui | 3 | 2 | [jpg](captures/current/shots/c1-owner/company_workforce_time.jpg) |
| `/company/workforce/time/config` | Planning & temps | 7 | 13 | 0 | 1 | oui | 3 | 0 | [jpg](captures/current/shots/c1-owner/company_workforce_time_config.jpg) |
| `/company/workforce/time/timesheets` | Planning & temps | 7 | 14 | 0 | 1 |  | 20 | 0 | [jpg](captures/current/shots/c1-owner/company_workforce_time_timesheets.jpg) |
| `/home` | Bienvenue, Djamel | 0 | 9 | 0 | 0 | oui | 0 | 0 | [jpg](captures/current/shots/c1-owner/home.jpg) |
