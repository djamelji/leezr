<script setup>
// ADR-834 — step C : modules of the activity, classified by the server (entitlement + commercial engines).
// Selecting a module adds its dependencies ; removing a dependency removes the modules that need it.
import { formatMoney } from '@/utils/money'
import { SELECTABLE, neededBy, toggleModule } from '@/modules/platform-admin/companies/companyCreationRules'

const props = defineProps({
  modules: { type: Array, default: () => [] },
  loading: { type: Boolean, default: false },
  planName: { type: String, default: '' },
  paidBlocked: { type: Boolean, default: false },
  removedAfterPlanChange: { type: Array, default: () => [] },
  currency: { type: String, default: 'EUR' },
})

const { t } = useI18n()

const selected = defineModel('selected', { type: Array, default: () => [] })

const base = computed(() => props.modules.filter(m => m.class === 'base' && !m.key.startsWith('core.')))
const optional = computed(() => props.modules.filter(m => SELECTABLE.includes(m.class)))
const blocked = computed(() => props.modules.filter(m => ['plan_required', 'unavailable'].includes(m.class)))

// modules selected only because another selected module needs them
const neededByMap = computed(() => neededBy(selected.value, props.modules))

const toggle = (module, on) => {
  selected.value = toggleModule(selected.value, module.key, on, props.modules)
}

const classLabel = m => m.class === 'plan_required'
  ? t('platformCompanyCreation.modules.classes.plan_required', { plan: m.required_plan?.name || '' })
  : t(`platformCompanyCreation.modules.classes.${m.class}`)

const classColor = { included: 'success', option: 'primary', with_dependency: 'info', plan_required: 'warning', unavailable: 'secondary' }
</script>

<template>
  <h4 class="text-h4 mb-1">
    {{ t('platformCompanyCreation.modules.heading') }}
  </h4>
  <p class="text-body-1 mb-2">
    {{ t('platformCompanyCreation.modules.description') }}
  </p>
  <p
    v-if="planName"
    class="text-body-2 text-medium-emphasis mb-6"
  >
    {{ t('platformCompanyCreation.modules.planHint', { plan: planName }) }}
  </p>

  <VSkeletonLoader
    v-if="loading"
    type="list-item-two-line@4"
  />

  <template v-else>
    <VAlert
      v-if="removedAfterPlanChange.length"
      type="warning"
      variant="tonal"
      class="mb-4"
    >
      {{ t('platformCompanyCreation.modules.removedAfterPlanChange', { modules: removedAfterPlanChange.join(', ') }) }}
    </VAlert>
    <VAlert
      v-if="paidBlocked"
      type="warning"
      variant="tonal"
      class="mb-4"
    >
      {{ t('platformCompanyCreation.modules.paidNeedsTrial') }}
    </VAlert>

    <h6 class="text-h6 mb-1">
      {{ t('platformCompanyCreation.modules.base') }}
    </h6>
    <p class="text-body-2 text-medium-emphasis mb-3">
      {{ t('platformCompanyCreation.modules.baseHint') }}
    </p>
    <div class="d-flex flex-wrap gap-2 mb-6">
      <VChip
        v-for="m in base"
        :key="m.key"
        color="primary"
        variant="tonal"
        :prepend-icon="m.icon || 'tabler-puzzle'"
      >
        {{ m.name }}
      </VChip>
    </div>

    <h6 class="text-h6 mb-3">
      {{ t('platformCompanyCreation.modules.optional') }}
    </h6>
    <p
      v-if="optional.length === 0"
      class="text-body-2 text-disabled"
    >
      {{ t('platformCompanyCreation.modules.none') }}
    </p>
    <VRow class="card-grid card-grid-sm">
      <VCol
        v-for="m in optional"
        :key="m.key"
        cols="12"
        sm="6"
      >
        <VCard
          border
          flat
          :class="selected.includes(m.key) ? 'border-primary border-opacity-100' : ''"
        >
          <VCardText class="d-flex gap-3">
            <VCheckbox
              :model-value="selected.includes(m.key)"
              :aria-label="m.name"
              hide-details
              density="compact"
              class="flex-grow-0"
              @update:model-value="on => toggle(m, on)"
            />
            <div class="flex-grow-1">
              <div class="d-flex flex-wrap align-center gap-2 mb-1">
                <VIcon
                  :icon="m.icon || 'tabler-puzzle'"
                  size="20"
                />
                <span class="text-h6">{{ m.name }}</span>
                <VChip
                  size="x-small"
                  :color="classColor[m.class]"
                  variant="tonal"
                >
                  {{ classLabel(m) }}
                </VChip>
              </div>
              <p class="text-body-2 mb-1">
                {{ m.description }}
              </p>
              <p
                v-if="m.class === 'option'"
                class="text-body-2 font-weight-medium mb-1"
              >
                {{ m.price > 0 ? t('platformCompanyCreation.modules.pricePerMonth', { price: formatMoney(m.price, { currency }) }) : t('platformCompanyCreation.modules.priceBySize') }}
              </p>
              <p
                v-if="m.requires.length"
                class="text-caption text-medium-emphasis mb-0"
              >
                {{ t('platformCompanyCreation.modules.requires', { modules: m.requires.map(r => r.name).join(', ') }) }}
              </p>
              <p
                v-if="neededByMap[m.key]"
                class="text-caption text-info mb-0"
              >
                {{ t('platformCompanyCreation.modules.autoAdded', { modules: neededByMap[m.key].join(', ') }) }}
              </p>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <template v-if="blocked.length">
      <h6 class="text-h6 mt-6 mb-3">
        {{ t('platformCompanyCreation.modules.unavailableSection') }}
      </h6>
      <VList
        density="compact"
        class="py-0"
      >
        <VListItem
          v-for="m in blocked"
          :key="m.key"
          :prepend-icon="m.icon || 'tabler-puzzle'"
          :title="m.name"
          :subtitle="classLabel(m)"
          disabled
        />
      </VList>
    </template>
  </template>
</template>
