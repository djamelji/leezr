<script setup>
// ADR-820 — étape « Données » de l'assistant d'un module : chaque BESOIN déclaré par le module est associé à un
// champ de la société (par identifiant stable), choisi parmi les champs COMPATIBLES, ou créé ici. Rien n'est
// associé automatiquement ; les valeurs existantes sont réutilisées (aucune copie). Seule l'opération qui dépend
// de la donnée est bloquée quand elle manque.
import { $api } from '@/utils/api'
import { useAppToast } from '@/composables/useAppToast'

const props = defineProps({ moduleKey: { type: String, required: true } })
const emit = defineEmits(['saved'])
const { t, te } = useI18n()
const { toast } = useAppToast()

const needs = ref([])
const loading = ref(true)
const busy = ref(null)
const choice = ref({})
const creating = ref({})
const newLabel = ref({})

const load = async () => {
  loading.value = true
  try {
    needs.value = (await $api(`/modules/${props.moduleKey}/data-needs`)).needs
    for (const n of needs.value) choice.value[n.key] = n.mapping?.field_definition_id ?? null
  }
  finally { loading.value = false }
}
onMounted(load)

const needLabel = n => (te(`moduleData.need.${n.key}.label`) ? t(`moduleData.need.${n.key}.label`) : n.label)
const needHint = n => (te(`moduleData.need.${n.key}.hint`) ? t(`moduleData.need.${n.key}.hint`) : n.hint)
const err = e => e?.data?.message || Object.values(e?.data?.errors || {})[0]?.[0] || t('common.error')

const bind = async n => {
  busy.value = n.key
  try {
    needs.value = (await $api(`/modules/${props.moduleKey}/data-needs/${n.key}`, { method: 'PUT', body: { field_definition_id: choice.value[n.key] } })).needs
    toast(t('moduleData.bound'), 'success')
    if (needs.value.every(x => x.mapping)) emit('saved')
  }
  catch (e) { toast(err(e), 'error') }
  finally { busy.value = null }
}
const create = async n => {
  busy.value = n.key
  try {
    needs.value = (await $api(`/modules/${props.moduleKey}/data-needs/${n.key}/field`, { method: 'POST', body: { label: newLabel.value[n.key] } })).needs
    for (const x of needs.value) choice.value[x.key] = x.mapping?.field_definition_id ?? null
    creating.value[n.key] = false
    toast(t('moduleData.created'), 'success')
    if (needs.value.every(x => x.mapping)) emit('saved')
  }
  catch (e) { toast(err(e), 'error') }
  finally { busy.value = null }
}
</script>

<template>
  <div data-test="module-data-needs">
    <p class="text-body-2 text-medium-emphasis mb-4">
      {{ t('moduleData.intro') }}
    </p>
    <VProgressLinear
      v-if="loading"
      indeterminate
    />
    <VCard
      v-for="n in needs"
      :key="n.key"
      variant="outlined"
      class="mb-4"
      :data-test="`data-need-${n.key}`"
    >
      <VCardItem>
        <VCardTitle>{{ needLabel(n) }}</VCardTitle>
        <VCardSubtitle class="text-wrap">
          {{ needHint(n) }}
        </VCardSubtitle>
        <template #append>
          <VChip
            size="small"
            label
            :color="n.mapping ? 'success' : 'warning'"
            variant="tonal"
          >
            {{ n.mapping ? t('moduleData.boundTo', { field: n.mapping.label }) : t('moduleData.notBound') }}
          </VChip>
        </template>
      </VCardItem>
      <VCardText>
        <div class="d-flex flex-wrap gap-2 mb-4">
          <VChip
            size="small"
            variant="outlined"
          >
            {{ t(`moduleData.type.${n.type}`) }}
          </VChip>
          <VChip
            v-if="n.unique"
            size="small"
            variant="outlined"
          >
            {{ t('moduleData.unique') }}
          </VChip>
          <VChip
            v-if="n.blocks"
            size="small"
            variant="outlined"
          >
            {{ t(`moduleData.blocks.${n.blocks}`) }}
          </VChip>
        </div>

        <div class="text-body-2 font-weight-medium mb-2">
          {{ t('moduleData.chooseField') }}
        </div>
        <VRadioGroup
          v-model="choice[n.key]"
          hide-details
          class="mb-2"
          :data-test="`data-need-${n.key}-choices`"
        >
          <VRadio
            v-for="c in n.candidates"
            :key="c.id"
            :value="c.id"
            :data-test="`data-need-candidate-${c.code}`"
          >
            <template #label>
              <span>{{ c.label }}</span>
              <span class="text-caption text-medium-emphasis ms-2">{{ c.origin === 'custom' ? t('moduleData.yourField') : t('moduleData.standardField') }}</span>
            </template>
          </VRadio>
        </VRadioGroup>
        <p
          v-if="!n.candidates.length"
          class="text-body-2 text-medium-emphasis"
        >
          {{ t('moduleData.noCandidate') }}
        </p>
        <div class="d-flex flex-wrap gap-2 align-center">
          <VBtn
            color="primary"
            :disabled="!choice[n.key] || choice[n.key] === n.mapping?.field_definition_id"
            :loading="busy === n.key && !creating[n.key]"
            :data-test="`data-need-${n.key}-bind`"
            @click="bind(n)"
          >
            {{ t('moduleData.confirmField') }}
          </VBtn>
          <VBtn
            v-if="n.can_create_field && !creating[n.key]"
            variant="text"
            prepend-icon="tabler-plus"
            :data-test="`data-need-${n.key}-new`"
            @click="creating[n.key] = true; newLabel[n.key] = needLabel(n)"
          >
            {{ t('moduleData.createField') }}
          </VBtn>
        </div>
        <div
          v-if="creating[n.key]"
          class="d-flex flex-wrap gap-2 align-center mt-3"
        >
          <AppTextField
            v-model="newLabel[n.key]"
            :label="t('moduleData.newFieldLabel')"
            style="flex: 1 1 260px"
            :data-test="`data-need-${n.key}-label`"
          />
          <VBtn
            color="primary"
            variant="tonal"
            :loading="busy === n.key"
            :disabled="!newLabel[n.key]"
            :data-test="`data-need-${n.key}-create`"
            @click="create(n)"
          >
            {{ t('moduleData.createAndUse') }}
          </VBtn>
          <VBtn
            variant="text"
            @click="creating[n.key] = false"
          >
            {{ t('common.cancel') }}
          </VBtn>
        </div>

        <template v-if="n.coverage">
          <VDivider class="my-4" />
          <div class="d-flex flex-wrap align-center gap-4 mb-2">
            <div>
              <div class="text-h6">
                {{ n.coverage.filled }} / {{ n.coverage.population }}
              </div>
              <div class="text-caption text-medium-emphasis">
                {{ t('moduleData.filled') }}
              </div>
            </div>
            <VProgressLinear
              :model-value="n.coverage.population ? (100 * n.coverage.filled) / n.coverage.population : 0"
              :color="n.coverage.missing_count ? 'warning' : 'success'"
              rounded
              height="8"
              style="flex: 1 1 200px"
            />
          </div>
          <VAlert
            v-if="n.coverage.missing_count"
            type="warning"
            variant="tonal"
            density="compact"
            class="mb-2"
            :data-test="`data-need-${n.key}-missing`"
          >
            {{ t('moduleData.missing', { n: n.coverage.missing_count }) }}
            <div class="mt-1">
              <RouterLink
                v-for="(p, i) in n.coverage.missing.slice(0, 10)"
                :key="p.employee_id"
                :to="`/company/people/employee-${p.employee_id}`"
              >
                {{ p.name }}{{ i < Math.min(9, n.coverage.missing.length - 1) ? ', ' : '' }}
              </RouterLink>
            </div>
          </VAlert>
          <VAlert
            v-for="d in n.coverage.duplicates"
            :key="d.value"
            type="error"
            variant="tonal"
            density="compact"
            class="mb-2"
          >
            {{ t('moduleData.duplicate', { value: d.value, people: d.people.join(', ') }) }}
          </VAlert>
          <p
            v-if="!n.coverage.population"
            class="text-body-2 text-medium-emphasis mb-0"
          >
            {{ t('moduleData.noPopulation') }}
          </p>
        </template>
      </VCardText>
    </VCard>
  </div>
</template>
