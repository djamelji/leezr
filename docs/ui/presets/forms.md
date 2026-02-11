# Presets UI — Forms

> Patterns de formulaires.
> Source : `resources/ui/presets/pages/templates/forms/`, `resources/ui/presets/`

## Éléments de formulaire (atoms)

AppTextField, AppSelect, AppAutocomplete, AppCombobox, AppTextarea, AppDateTimePicker

## Éléments custom

CustomCheckboxes, CustomCheckboxesWithIcon, CustomCheckboxesWithImage,
CustomRadios, CustomRadiosWithIcon, CustomRadiosWithImage

## Patterns disponibles

| Pattern | Source | Description |
|---------|--------|-------------|
| Form Layouts | `pages/forms/form-layouts.vue` | Horizontal, vertical, multi-col, tabbed, collapsible |
| Form Validation | `pages/forms/form-validation.vue` | Règles de validation standard |
| Form Wizard Numbered | `pages/forms/form-wizard-numbered.vue` | Stepper numéroté |
| Form Wizard Icons | `pages/forms/form-wizard-icons.vue` | Stepper avec icônes |
| Checkout Wizard | `pages/wizard-examples/checkout.vue` | 4 steps e-commerce |
| Property Listing Wizard | `pages/wizard-examples/property-listing.vue` | 5 steps immobilier |
| Create Deal Wizard | `pages/wizard-examples/create-deal.vue` | 4 steps commercial |

## Drawers de création (pattern récurrent)

| Drawer | Module | Champs |
|--------|--------|--------|
| AddNewUserDrawer | User | name, email, company, country, role, plan |
| AddCustomerDrawer | E-commerce | name, email, country |
| AddCategoryDrawer | E-commerce | name, description (TiptapEditor), image, status |
| CalendarEventHandler | Calendar | title, calendar, dates, URL, guests, location, description |
| KanbanBoardEditDrawer | Kanban | title, due date, label, assignees |
| InvoiceAddPaymentDrawer | Invoice | amount, date, method, note |
| InvoiceSendInvoiceDrawer | Invoice | to, subject, message |

## Composant clé : AppStepper

- Direction : horizontal ou vertical
- Mode : numéros ou icônes
- Validation : état erreur par step
- Alignement : center, start, end

## Extraits

_Aucun pour l'instant._
