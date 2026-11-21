<script setup>
// ADR-834 — step F : starting configuration of the activity, in business terms. Library = available, never
// required ; requirements stay the company's decision ; organisation / planning may be applied now or left.
const { t } = useI18n()

const props = defineProps({
  initialization: { type: Object, default: null },
  loading: { type: Boolean, default: false },
})

const planning = defineModel('planning', { type: Boolean })
const organization = defineModel('organization', { type: Boolean })

const sum = (counts, keys) => keys.reduce((n, k) => n + (counts?.[k] || 0), 0)
const total = counts => Object.values(counts || {}).reduce((a, b) => a + b, 0)

const presets = computed(() => props.initialization?.presets || [])

const decisionModel = kind => kind === 'planning' ? planning : organization

const roleChip = item => item.owner
  ? { color: 'primary', text: t('platformCompanyCreation.initialization.roleOwner') }
  : item.automatic
    ? { color: 'success', text: t('platformCompanyCreation.initialization.roleAuto') }
    : { color: 'warning', text: t('platformCompanyCreation.initialization.roleToConfirm') }
</script>

<template>
  <h4 class="text-h4 mb-1">
    {{ t('platformCompanyCreation.initialization.heading') }}
  </h4>
  <p class="text-body-1 mb-6">
    {{ t('platformCompanyCreation.initialization.description') }}
  </p>

  <VSkeletonLoader
    v-if="loading || !initialization"
    type="list-item-three-line@3"
  />

  <template v-else>
    <VChip
      class="mb-4"
      :color="initialization.path === 'versioned' ? 'success' : 'secondary'"
      variant="tonal"
      prepend-icon="tabler-versions"
    >
      {{ t(`platformCompanyCreation.initialization.${initialization.path}`) }}
    </VChip>

    <VAlert
      v-if="initialization.path === 'historical' && initialization.historical"
      type="info"
      variant="tonal"
    >
      {{ t('platformCompanyCreation.initialization.historicalSummary', { roles: initialization.historical.roles.length, fields: initialization.historical.fields, documents: initialization.historical.documents }) }}
    </VAlert>

    <VRow class="card-grid card-grid-sm">
      <VCol
        v-for="p in presets"
        :key="p.kind"
        cols="12"
        md="6"
      >
        <VCard
          border
          flat
        >
          <VCardItem>
            <VCardTitle>{{ t(`platformCompanyCreation.initialization.kinds.${p.kind}`) }}</VCardTitle>
            <VCardSubtitle>{{ p.label }} · {{ t('platformCompanyCreation.initialization.version', { version: p.version }) }}</VCardSubtitle>
          </VCardItem>
          <VCardText>
            <p
              v-if="p.kind === 'catalog'"
              class="mb-0"
            >
              {{ t('platformCompanyCreation.initialization.catalogSummary', { fields: sum(p.counts, ['company>field', 'person>field']), documents: sum(p.counts, ['company>document', 'person>document']) }) }}
            </p>

            <VList
              v-else-if="p.kind === 'rbac'"
              density="compact"
              class="py-0"
            >
              <VListItem
                v-for="item in p.items"
                :key="item.label"
                class="px-0"
              >
                <div class="d-flex flex-wrap align-center justify-space-between gap-2">
                  <span class="text-body-1">{{ item.label }}</span>
                  <VChip
                    size="x-small"
                    :color="roleChip(item).color"
                    variant="tonal"
                  >
                    {{ roleChip(item).text }}
                  </VChip>
                </div>
              </VListItem>
            </VList>

            <p
              v-else-if="p.kind === 'requirements'"
              class="mb-0"
            >
              {{ t('platformCompanyCreation.initialization.requirementsHint', { count: total(p.counts) }) }}
            </p>

            <template v-else-if="p.confirmable">
              <p class="text-body-2 mb-2">
                {{ p.items.length ? p.items.map(i => i.label).join(', ') : t('platformCompanyCreation.initialization.elements', { count: total(p.counts) }) }}
              </p>
              <VRadioGroup
                v-model="decisionModel(p.kind).value"
                density="compact"
                hide-details
              >
                <VRadio
                  :value="false"
                  :label="t('platformCompanyCreation.initialization.leaveToCompany')"
                />
                <VRadio
                  :value="true"
                  :label="t('platformCompanyCreation.initialization.applyNow')"
                />
              </VRadioGroup>
            </template>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>
  </template>
</template>
