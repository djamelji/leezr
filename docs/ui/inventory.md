# Inventaire UI - Vue globale du stock

> Référence rapide du stock UI Vuexy disponible.

## Stock disponible

| Catégorie | Presets dispo | Emplacement preset |
|-----------|--------------|-------------------|
| Atoms | 14 | `resources/js/@core/components/`, `resources/js/@core/components/app-form-elements/` |
| Molecules | 18 | `resources/js/@core/components/`, `resources/js/components/` |
| Organisms | 6 | `resources/js/@core/components/`, `resources/js/layouts/components/` |
| Dialogs | 20 | `resources/ui/presets/dialogs/` |
| Tables | 15+ patterns | `resources/ui/presets/apps/`, `resources/ui/presets/pages/templates/tables/` |
| Forms | 19+ patterns | `resources/ui/presets/pages/templates/forms/`, `resources/ui/presets/apps/` |
| Cards | 45 presets | `resources/ui/presets/cards/` |
| Dashboards | 32 widgets | `resources/ui/presets/dashboards/` |
| Layouts | 4 types + 6 navbar | `resources/js/layouts/`, `resources/js/@layouts/` |
| Auth | 13 pages | `resources/ui/presets/auth/` |
| Pages | 20+ | `resources/ui/presets/pages/` |
| Charts | 18 | `resources/ui/presets/charts/` |
| Apps views | 67 | `resources/ui/presets/apps/` |
| Navigation | 10+ configs | `resources/ui/presets/navigation/` |
| Wizards | 3 patterns | `resources/ui/presets/pages/templates/wizard-examples/` |
| Front | 10 sections | `resources/ui/presets/front/` |

## Patterns UI métier documentés

### Payment Method Card (ADR-243)

Pattern pour afficher un moyen de paiement (carte bancaire ou SEPA) dans une grille uniforme.

**Source** : `CardSolid.vue` (couleur) + `AccountSettingsBillingAndPlans.vue` (info card) adaptés.

**Grid** : `VCol cols="12" sm="6"` — 2 par ligne dès tablette.

**Structure** :
```vue
<VCard flat border class="h-100">
  <VCardItem>
    <template #prepend>
      <VIcon :icon="..." size="28" class="me-2" />  <!-- Sans fond, theme-aware -->
    </template>
    <VCardTitle>...</VCardTitle>
    <template #append>
      <!-- Chips + IconBtn actions -->
    </template>
  </VCardItem>
  <VCardText class="pt-0">
    <!-- Détails : numéro masqué monospace, expiry, domiciliation -->
  </VCardText>
</VCard>
```

**Règles** :
- Icône sans VAvatar (pas de fond), couleur héritée du texte (dark/light automatique)
- `h-100` pour hauteur uniforme dans la grille
- Numéro masqué en `font-family: 'Courier New', monospace; letter-spacing: 1px`
- Carte : brand icon Tabler + numéro + expiry + funding chip + pays
- SEPA : bank icon + holder name + IBAN masqué + domiciliation (banque, BIC, pays)
- Badge défaut : `VChip size="x-small" color="success" variant="tonal"`
- Border primary si défaut : `:style="pm.is_default ? 'border-color: rgb(var(--v-theme-primary))' : ''"`

**Fichier** : `resources/js/pages/company/billing/_BillingPaymentMethods.vue`

---

## Statut d'extraction

| Catégorie | Extraits | Restants |
|-----------|----------|----------|
| _aucune extraction effectuée_ | 0 | tout |

> Les presets seront extraits **à la demande**, quand un besoin métier les requiert.
