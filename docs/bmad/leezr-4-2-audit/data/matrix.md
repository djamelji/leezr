| Page | Portée | Module | Permission | Profils testés | État | Problèmes |
|---|---|---|---|---|---|---|
| `/:error(.*)` | company | — | — | p-creator, p-superadmin-w2 | OK×2 | redirige → /platform/access/users; redirige → /platform/companies |
| `/account-settings/:tab` | company | — | — | c1-consultation, c1-dispatcher, c1-driver, c1-manager, c1-owner, c1-owner-w2, c1-pay, c1-salarie, c2-trial, c22-limited, c3-pending-starter, c5-beta, c58-vortex-beta | OK×13 | — |
| `/company/403` | company | — | — | c1-consultation, c1-dispatcher, c1-driver, c1-manager, c1-owner-w2, c1-pay, c1-salarie, c22-limited | 403×8 | redirige → /company/403 |
| `/company/audit` | company | — | — | c1-consultation, c1-dispatcher, c1-driver, c1-manager, c1-owner, c1-owner-w2, c1-pay, c1-salarie, c2-trial, c22-limited, c3-pending-starter, c5-beta, c58-vortex-beta | 403×7 / OK×6 | redirige → /company/403 |
| `/company/billing` | company | — | — | c1-consultation, c1-dispatcher, c1-driver, c1-manager, c1-owner, c1-pay, c1-salarie, c2-trial, c22-limited, c3-pending-starter, c5-beta, c58-vortex-beta | 403×7 / ERREUR-API×5 | 404 GET /api/company/billing/timeline; redirige → /company/403; redirige → /company/billing/overview |
| `/company/billing/:tab` | company | core.billing | billing.manage | c1-owner, c1-owner-w2, c2-trial, c3-pending-starter, c5-beta, c58-vortex-beta | ERREUR-API×6 | 404 GET /api/company/billing/timeline |
| `/company/billing/consolidated-preview` | company | core.billing | billing.manage | c1-consultation, c1-dispatcher, c1-driver, c1-manager, c1-owner, c1-pay, c1-salarie, c2-trial, c22-limited, c3-pending-starter, c5-beta, c58-vortex-beta | 403×7 / OK×5 | redirige → /company/403 |
| `/company/billing/invoices/:id` | company | core.billing | billing.manage | c1-owner, c5-beta, c58-vortex-beta | OK×3 | — |
| `/company/billing/pay` | company | core.billing | billing.manage | c1-consultation, c1-dispatcher, c1-driver, c1-manager, c1-owner, c1-owner-w2, c1-pay, c1-salarie, c2-trial, c22-limited, c3-pending-starter, c5-beta, c58-vortex-beta | 403×7 / OK×6 | redirige → /company/403 |
| `/company/catalogs/:tab` | company | — | — | c1-dispatcher, c1-owner, c1-owner-w2, c2-trial, c3-pending-starter, c5-beta, c58-vortex-beta | OK×7 | — |
| `/company/dev-lab` | company | workforce | workforce.view | c1-consultation, c1-dispatcher, c1-driver, c1-manager, c1-owner, c1-pay, c1-salarie, c2-trial, c22-limited, c3-pending-starter, c5-beta, c58-vortex-beta | OK×12 | redirige → / |
| `/company/documents/:tab` | company | — | — | c1-consultation, c1-dispatcher, c1-manager, c1-owner, c1-owner-w2, c2-trial, c22-limited, c3-pending-starter, c5-beta, c58-vortex-beta | 403×3 / OK×7 | redirige → /company/403 |
| `/company/members` | company | — | — | c1-consultation, c1-dispatcher, c1-driver, c1-manager, c1-owner, c1-pay, c1-salarie, c2-trial, c22-limited, c3-pending-starter, c5-beta, c58-vortex-beta | 403×6 / OK×6 | redirige → /company/403; redirige → /company/people |
| `/company/members/:id` | company | core.members | members.view' } }) | c1-owner-w2 | OK×1 | redirige → /company/people/employee-2 |
| `/company/modules` | company | core.modules | modules.manage' } }) | c1-consultation, c1-dispatcher, c1-driver, c1-manager, c1-owner, c1-owner-w2, c1-pay, c1-salarie, c2-trial, c22-limited, c3-pending-starter, c5-beta, c58-vortex-beta | 403×7 / OK×6 | redirige → /company/403 |
| `/company/modules/:key` | company | core.modules | modules.manage' } }) | c1-owner, c2-trial, c3-pending-starter, c5-beta, c58-vortex-beta | OK×5 | — |
| `/company/my-deliveries` | company | — | — | c1-dispatcher, c1-driver, c1-owner, c2-trial, c3-pending-starter | OK×5 | — |
| `/company/my-deliveries/:id` | company | logistics_shipments | shipments.view_own' } }) | c1-driver | OK×1 | — |
| `/company/my-referrals` | company | — | — | c1-owner-w2 | OK×1 | redirige → /home |
| `/company/my-space/:tab` | company | — | — | c1-consultation, c1-dispatcher, c1-driver, c1-manager, c1-owner, c1-owner-w2, c1-pay, c1-salarie, c2-trial, c22-limited, c3-pending-starter, c5-beta, c58-vortex-beta | OK×13 | — |
| `/company/notifications` | company | — | — | c1-consultation, c1-dispatcher, c1-driver, c1-manager, c1-owner, c1-pay, c1-salarie, c2-trial, c22-limited, c3-pending-starter, c5-beta, c58-vortex-beta | OK×12 | — |
| `/company/people` | company | — | — | c1-consultation, c1-dispatcher, c1-manager, c1-owner, c1-owner-w2, c2-trial, c22-limited, c3-pending-starter, c5-beta, c58-vortex-beta | OK×10 | — |
| `/company/people/:personRef/:tab?` | company | — | — | c1-owner-w2 | OK×1 | redirige → /company/people/employee-2 |
| `/company/people/hr` | company | — | — | c1-consultation, c1-dispatcher, c1-driver, c1-manager, c1-owner, c1-owner-w2, c1-pay, c1-salarie, c2-trial, c22-limited, c3-pending-starter, c5-beta, c58-vortex-beta | OK×11 / 403×2 | redirige → /company/403 |
| `/company/people/roles` | company | — | — | c1-consultation, c1-dispatcher, c1-driver, c1-manager, c1-owner, c1-owner-w2, c1-pay, c1-salarie, c2-trial, c22-limited, c3-pending-starter, c5-beta, c58-vortex-beta | OK×11 / 403×2 | redirige → /company/403 |
| `/company/plan` | company | — | — | c1-consultation, c1-dispatcher, c1-driver, c1-manager, c1-owner, c1-pay, c1-salarie, c2-trial, c22-limited, c3-pending-starter, c5-beta, c58-vortex-beta | 403×7 / ERREUR-API×5 | 404 GET /api/company/billing/timeline; redirige → /company/403; redirige → /company/billing/plan |
| `/company/profile/:tab` | company | — | — | c1-consultation, c1-dispatcher, c1-driver, c1-manager, c1-owner, c1-owner-w2, c1-pay, c1-salarie, c2-trial, c22-limited, c3-pending-starter, c5-beta, c58-vortex-beta | 403×6 / OK×7 | redirige → /company/403 |
| `/company/roles` | company | core.roles | roles.view' } }) | c1-owner-w2 | OK×1 | redirige → /company/people/roles |
| `/company/settings` | company | — | — | c1-consultation, c1-dispatcher, c1-driver, c1-manager, c1-owner, c1-owner-w2, c1-pay, c1-salarie, c2-trial, c22-limited, c3-pending-starter, c5-beta, c58-vortex-beta | OK×13 | — |
| `/company/shipments` | company | — | — | c1-dispatcher, c1-owner, c1-owner-w2, c2-trial, c3-pending-starter | OK×5 | — |
| `/company/shipments/:id` | company | logistics_shipments | shipments.view' } }) | c1-dispatcher, c1-owner | OK×2 | — |
| `/company/shipments/create` | company | — | — | c1-dispatcher, c1-owner, c2-trial, c3-pending-starter | OK×4 | — |
| `/company/support` | company | core.support | support.view | c1-consultation, c1-dispatcher, c1-driver, c1-manager, c1-owner, c1-owner-w2, c1-pay, c1-salarie, c2-trial, c22-limited, c3-pending-starter, c5-beta, c58-vortex-beta | 403×6 / OK×7 | redirige → /company/403 |
| `/company/support/:id` | company | core.support | support.view | NON TESTÉE | — | — |
| `/company/workflows` | company | core.workflows | automations.view | c1-consultation, c1-dispatcher, c1-driver, c1-manager, c1-owner, c1-owner-w2, c1-pay, c1-salarie, c2-trial, c22-limited, c3-pending-starter, c5-beta, c58-vortex-beta | 403×7 / OK×6 | redirige → /company/403 |
| `/company/workflows/:id` | company | core.workflows | automations.view | NON TESTÉE | — | — |
| `/company/workforce` | company | — | — | c1-consultation, c1-dispatcher, c1-driver, c1-manager, c1-owner, c1-pay, c1-salarie, c2-trial, c22-limited, c3-pending-starter, c5-beta, c58-vortex-beta | OK×10 / 403×2 | redirige → /company/403; redirige → /home |
| `/company/workforce/configuration` | company | — | — | c1-consultation, c1-dispatcher, c1-driver, c1-manager, c1-owner, c1-pay, c1-salarie, c2-trial, c22-limited, c3-pending-starter, c5-beta, c58-vortex-beta | 403×6 / OK×6 | redirige → /company/403; redirige → /company/workforce/settings/worktime; redirige → /home |
| `/company/workforce/contract/templates` | company | — | — | c1-consultation, c1-dispatcher, c1-driver, c1-manager, c1-owner, c1-pay, c1-salarie, c2-trial, c22-limited, c3-pending-starter, c5-beta, c58-vortex-beta | 403×6 / OK×6 | redirige → /company/403; redirige → /company/documents/templates; redirige → /home |
| `/company/workforce/contracts` | company | — | — | c1-consultation, c1-manager, c1-owner, c1-owner-w2, c22-limited, c5-beta, c58-vortex-beta | OK×7 | — |
| `/company/workforce/documents` | company | — | — | c1-consultation, c1-dispatcher, c1-driver, c1-manager, c1-owner, c1-pay, c1-salarie, c2-trial, c22-limited, c3-pending-starter, c5-beta, c58-vortex-beta | 403×5 / OK×7 | redirige → /company/403; redirige → /company/documents/people; redirige → /home |
| `/company/workforce/dsn` | company | — | — | c1-owner-w2 | OK×1 | — |
| `/company/workforce/dsn/:id` | company | — | — | NON TESTÉE | — | — |
| `/company/workforce/employees` | company | — | — | c1-consultation, c1-dispatcher, c1-driver, c1-manager, c1-owner, c1-pay, c1-salarie, c2-trial, c22-limited, c3-pending-starter, c5-beta, c58-vortex-beta | OK×11 / 403×1 | redirige → /company/403; redirige → /company/people/hr; redirige → /home |
| `/company/workforce/employees/:id` | company | — | — | c1-owner-w2 | OK×1 | redirige → /company/people/employee-1 |
| `/company/workforce/hiring` | company | — | — | c1-owner, c1-owner-w2, c5-beta, c58-vortex-beta | OK×4 | — |
| `/company/workforce/hiring/:id` | company | — | — | c1-owner | OK×1 | — |
| `/company/workforce/leave/:tab?` | company | workforce_leave | workforce.leave_request | c1-manager, c1-owner, c1-owner-w2, c5-beta, c58-vortex-beta | OK×5 | — |
| `/company/workforce/me` | company | workforce' } }) | — | c1-dispatcher, c1-driver, c1-owner, c1-owner-w2, c1-salarie, c22-limited, c5-beta | OK×7 | — |
| `/company/workforce/onboarding` | company | — | — | c1-consultation, c1-manager, c1-owner, c22-limited, c5-beta, c58-vortex-beta | 403×3 / OK×3 | redirige → /company/403 |
| `/company/workforce/organization/:tab` | company | — | — | c1-consultation, c1-manager, c1-owner, c1-owner-w2, c22-limited, c5-beta, c58-vortex-beta | OK×7 | — |
| `/company/workforce/organization/positions/:id/:tab?` | company | — | — | c1-consultation, c1-manager, c1-owner | OK×3 | — |
| `/company/workforce/payroll/:id` | company | workforce_payroll | workforce.payroll_prepare' } }) // ADR-8 | NON TESTÉE | — | — |
| `/company/workforce/payroll/:tab?` | company | — | — | c1-owner, c1-owner-w2, c1-pay, c5-beta, c58-vortex-beta | OK×5 | — |
| `/company/workforce/planning` | company | workforce_planning | workforce.planning_view' } }) | c1-manager, c1-owner, c1-owner-w2, c5-beta, c58-vortex-beta | OK×5 | — |
| `/company/workforce/referrals/:tab?` | company | workforce_referral | workforce_referral.review' } }) | c1-owner-w2 | OK×1 | redirige → /home |
| `/company/workforce/requirements` | company | — | — | c1-consultation, c1-dispatcher, c1-driver, c1-manager, c1-owner, c1-owner-w2, c1-pay, c1-salarie, c2-trial, c22-limited, c3-pending-starter, c5-beta, c58-vortex-beta | 403×6 / OK×7 | redirige → /company/403; redirige → /home |
| `/company/workforce/settings/:tab` | company | — | — | c1-manager, c1-owner, c1-owner-w2, c5-beta, c58-vortex-beta | OK×5 | — |
| `/company/workforce/signature` | company | — | — | c1-consultation, c1-dispatcher, c1-driver, c1-manager, c1-owner, c1-pay, c1-salarie, c2-trial, c22-limited, c3-pending-starter, c5-beta, c58-vortex-beta | 403×6 / OK×6 | redirige → /company/403; redirige → /company/documents/signatures; redirige → /home |
| `/company/workforce/time/:id` | company | workforce | workforce.timesheet_view' } }) | NON TESTÉE | — | — |
| `/company/workforce/time/:tab?` | company | — | — | c1-manager, c1-owner, c1-owner-w2, c1-salarie, c5-beta, c58-vortex-beta | OK×6 | — |
| `/dashboard` | company | — | — | c1-consultation, c1-dispatcher, c1-driver, c1-manager, c1-owner, c1-pay, c1-salarie, c2-trial, c22-limited, c3-pending-starter, c5-beta, c58-vortex-beta | OK×12 | redirige → /home |
| `/onboarding` | company | — | — | c1-consultation, c1-dispatcher, c1-driver, c1-manager, c1-owner, c1-pay, c1-salarie, c2-trial, c22-limited, c3-pending-starter, c5-beta, c58-vortex-beta | ERREUR-API×12 | 401 GET /api/public/onboarding |
| `/ui-lab` | company | — | — | NON TESTÉE | — | — |
| `/platform` | platform | platform.dashboard | — | p-superadmin-w2 | OK×1 | redirige → /platform/dashboard/overview |
| `/platform/access/:tab` | platform | platform.users | manage_platform_users | p-govmgr, p-superadmin, p-superadmin-w2 | OK×3 | — |
| `/platform/account/:tab` | platform | — | — | p-superadmin | OK×1 | — |
| `/platform/billing/:tab` | platform | platform.billing | view_billing | p-finance, p-superadmin, p-superadmin-w2, p-support | KO×1 / OK×3 | 403 GET /api/platform/billing/coupons; 403 GET /api/platform/plans; FetchError: [GET] "/api/platform/plans": 403 Forbidden
    at async r (http://127.0.0.1:8842/build/assets/main-CLQVixb0.js:52:74336)
    at async Proxy. |
| `/platform/billing/consolidated-preview` | platform | — | — | p-finance, p-superadmin | OK×2 | — |
| `/platform/billing/invoices/:id` | platform | — | — | p-finance, p-superadmin | OK×2 | — |
| `/platform/catalog/:tab` | platform | platform.modules | manage_modules | p-govmgr, p-superadmin, p-superadmin-w2 | OK×3 | — |
| `/platform/communications/:tab` | platform | platform.support | manage_support | p-superadmin, p-superadmin-w2, p-support | OK×3 | — |
| `/platform/companies` | platform | platform.companies | view_companies | p-creator, p-finance, p-superadmin, p-superadmin-w2, p-support | OK×4 / KO×1 | 403 GET /api/platform/activity/types; 403 GET /api/platform/activity?page=1&per_page=30; [GET] "/api/platform/activity?page=1&per_page=30": 403 Forbidden; redirige → /platform/communications/support; redirige → /platform |
| `/platform/companies/:id/:tab?` | platform | platform.companies | view_companies | p-creator, p-finance, p-govmgr, p-superadmin, p-superadmin-w2 | 403×1 / KO×1 / OK×3 | 403 GET /api/platform/activity/types; 403 GET /api/platform/activity?page=1&per_page=30; 403 GET /api/platform/companies/1; [GET] "/api/platform/activity?page=1&per_page=30": 403 Forbidden; redirige → /platform/catalog/m |
| `/platform/companies/create` | platform | platform.companies | create_companies | p-creator, p-govmgr, p-superadmin, p-superadmin-w2 | OK×4 | redirige → /platform/catalog/modules |
| `/platform/contract-templates` | platform | — | — | p-govmgr, p-superadmin, p-superadmin-w2 | OK×3 | — |
| `/platform/dashboard/:tab` | platform | platform.dashboard | view_dashboard | p-finance, p-superadmin, p-superadmin-w2 | KO×1 / OK×2 | 403 GET /api/platform/activity/types; 403 GET /api/platform/activity?page=1&per_page=30; [GET] "/api/platform/activity?page=1&per_page=30": 403 Forbidden |
| `/platform/documentation/:slug` | platform | platform.documentation | manage_documentation | p-superadmin | OK×1 | — |
| `/platform/documents/:id` | platform | platform.documents | manage_document_catalog | p-superadmin | OK×1 | — |
| `/platform/forgot-password` | platform | — | — | pub-visitor | OK×1 | — |
| `/platform/jobdomains/:id` | platform | platform.jobdomains | manage_jobdomains | p-govmgr, p-superadmin | OK×2 | redirige → /platform/jobdomains/1/general |
| `/platform/jobdomains/:id/:tab` | platform | platform.jobdomains | manage_jobdomains | p-govmgr, p-superadmin | OK×2 | — |
| `/platform/login` | platform | — | — | pub-visitor | OK×1 | — |
| `/platform/markets/:key` | platform | platform.markets | manage_markets | p-superadmin | OK×1 | — |
| `/platform/messaging/:tab` | platform | platform.email | manage_email | p-superadmin, p-superadmin-w2 | OK×2 | — |
| `/platform/modules/:key/:tab?` | platform | platform.modules | manage_modules | p-govmgr, p-superadmin | OK×2 | — |
| `/platform/operations/:tab` | platform | platform.alerts | view_system_health | p-superadmin, p-superadmin-w2 | OK×2 | — |
| `/platform/organization-presets` | platform | — | — | p-govmgr, p-superadmin | OK×2 | — |
| `/platform/organization-presets/:id` | platform | — | — | p-superadmin | OK×1 | — |
| `/platform/plans/:key` | platform | platform.plans | manage_plans | p-govmgr, p-superadmin | OK×2 | — |
| `/platform/presentation-lab` | platform | — | — | p-superadmin-w2 | OK×1 | redirige → / |
| `/platform/reset-password` | platform | — | — | pub-visitor | OK×1 | — |
| `/platform/settings/:tab` | platform | platform.settings | manage_theme_settings | p-finance, p-superadmin, p-superadmin-w2 | KO×1 / OK×2 | 403 GET /api/platform/activity/types; 403 GET /api/platform/activity?page=1&per_page=30; [GET] "/api/platform/activity?page=1&per_page=30": 403 Forbidden; redirige → /platform/dashboard/overview |
| `/platform/support/:id` | platform | platform.support | manage_support | NON TESTÉE | — | — |
| `/platform/users/:id` | platform | platform.users | manage_platform_users | p-govmgr, p-superadmin | OK×2 | — |
| `/` | public/auth | — | — | c1-consultation, c1-dispatcher, c1-driver, c1-manager, c1-owner, c1-pay, c1-salarie, c2-trial, c22-limited, c3-pending-starter, c5-beta, c58-vortex-beta, p-superadmin-w2, pub-visitor | OK×14 | redirige → / |
| `/audience/confirm` | public/auth | — | — | pub-visitor | OK×1 | — |
| `/audience/unsubscribe` | public/auth | — | — | pub-visitor | OK×1 | — |
| `/forgot-password` | public/auth | — | — | pub-visitor | OK×1 | — |
| `/help-center` | public/auth | — | — | pub-visitor | OK×1 | — |
| `/help-center/:topicSlug` | public/auth | — | — | pub-visitor | OK×1 | — |
| `/help-center/:topicSlug/:articleSlug` | public/auth | — | — | pub-visitor | OK×1 | — |
| `/home` | public/auth | — | — | c1-consultation, c1-dispatcher, c1-driver, c1-manager, c1-owner, c1-owner-w2, c1-pay, c1-salarie, c2-trial, c22-limited, c3-pending-starter, c5-beta, c58-vortex-beta, pub-visitor | OK×13 / ERREUR-API×1 | 401 GET /api/me; 401 GET /api/notifications/unread-count; redirige → /login |
| `/invitations` | public/auth | — | — | pub-visitor | ERREUR-API×1 | 401 GET /api/my-invitations |
| `/login` | public/auth | — | — | pub-visitor | OK×1 | — |
| `/maintenance` | public/auth | — | — | pub-visitor | OK×1 | — |
| `/referral` | public/auth | — | — | pub-visitor | OK×1 | — |
| `/register` | public/auth | — | — | c1-consultation, c1-dispatcher, c1-driver, c1-manager, c1-owner, c1-pay, c1-salarie, c2-trial, c22-limited, c3-pending-starter, c5-beta, c58-vortex-beta, pub-visitor | OK×13 | — |
| `/reset-password` | public/auth | — | — | pub-visitor | OK×1 | — |
| `/sign/:token` | public/auth | — | — | pub-visitor | ERREUR-API×1 | 404 GET /api/public/signature/invalid-token-audit |
