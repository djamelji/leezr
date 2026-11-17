<script setup>
// ADR-821 — modules de l'offre, LUS depuis les modules (palier minimal, prix d'option) : inclus, option, non inclus,
// dépendances et couverture. Le plan ne porte pas de liste de modules ; on modifie sur la fiche du module, qui
// refuse toute modification créant une dépendance non couverte.
import { $platformApi } from '@/utils/platformApi'
import { useCatalogText } from '@/composables/useCatalogText'

const props = defineProps({ planKey: { type: String, required: true } })
const { t } = useI18n()
const { moduleName } = useCatalogText()
const data = ref(null)
const filter = ref('included')
onMounted(async () => { data.value = await $platformApi(`/plans/${props.planKey}/modules`).catch(() => null) })
const TONE = { included: 'success', option: 'warning', not_included: 'secondary' }
const rows = computed(() => (data.value?.modules || []).filter(m => filter.value === 'all' || m.status === filter.value))
const count = s => (data.value?.modules || []).filter(m => s === 'all' || m.status === s).length
const gaps = computed(() => (data.value?.modules || []).filter(m => m.gaps.length))
</script>

<template>
  <VCard
    class="mt-6"
    data-test="plan-modules"
  >
    <VCardItem>
      <VCardTitle>{{ t('planModules.title') }}</VCardTitle>
      <VCardSubtitle class="text-wrap">
        {{ t('planModules.hint') }}
      </VCardSubtitle>
    </VCardItem>
    <VCardText v-if="!data">
      <VSkeletonLoader type="list-item-two-line" />
    </VCardText>
    <template v-else>
      <VCardText class="pb-0">
        <VAlert
          v-if="gaps.length"
          type="warning"
          variant="tonal"
          density="compact"
          class="mb-4"
          data-test="plan-modules-gaps"
        >
          {{ t('planModules.gaps', { list: gaps.map(m => `${moduleName(m.key, m.name)} → ${m.gaps.map(g => moduleName(g.key, g.name)).join(', ')}`).join(' ; ') }) }}
        </VAlert>
        <VChipGroup
          v-model="filter"
          mandatory
          selected-class="text-primary"
        >
          <VChip
            v-for="s in ['included', 'option', 'not_included', 'all']"
            :key="s"
            :value="s"
            variant="tonal"
            label
          >
            {{ t(`planModules.filter.${s}`) }} ({{ count(s) }})
          </VChip>
        </VChipGroup>
      </VCardText>
      <VTable class="text-no-wrap">
        <thead>
          <tr>
            <th>{{ t('planModules.module') }}</th>
            <th>{{ t('planModules.inOffer') }}</th>
            <th>{{ t('planModules.dependencies') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="m in rows"
            :key="m.key"
            :data-test="`plan-module-${m.key}`"
          >
            <td>
              <RouterLink :to="{ name: 'platform-modules-key', params: { key: m.key } }">
                {{ moduleName(m.key, m.name) }}
              </RouterLink>
            </td>
            <td>
              <VChip
                size="small"
                label
                variant="tonal"
                :color="TONE[m.status]"
              >
                {{ t(`planModules.status.${m.status}`) }}
              </VChip>
            </td>
            <td>
              <span
                v-if="!m.dependencies.length"
                class="text-disabled"
              >—</span>
              <VChip
                v-for="d in m.dependencies"
                :key="d.key"
                size="x-small"
                label
                class="me-1"
                :color="m.gaps.some(g => g.key === d.key) ? 'error' : TONE[d.status]"
                variant="tonal"
                :data-test="`plan-module-dep-${m.key}-${d.key}`"
              >
                {{ t('planModules.mandatoryDependency') }} : {{ moduleName(d.key, d.name) }} · {{ t(`planModules.status.${d.status}`) }}
              </VChip>
            </td>
          </tr>
        </tbody>
      </VTable>
    </template>
  </VCard>
</template>
