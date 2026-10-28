<script setup>
// ADR-639 (W-B) — section de règles RH (pauses / temps de travail) : AGRÈGE l'endpoint existant
// /workforce/config/{domain}. Affiche la valeur effective + la PROVENANCE (market/convention/jobdomain/
// company/défaut) + la mutabilité (obligatoire = verrouillé, configurable = éditable). Aucun second moteur.
import { $api } from '@/utils/api'
import { useAppToast } from '@/composables/useAppToast'

const props = defineProps({
  domain: { type: String, required: true }, // 'breaks' | 'work-time'
  readOnly: { type: Boolean, default: false },
})

const { t } = useI18n()
const { toast } = useAppToast()

const rules = ref([])
const loading = ref(true)
const edits = ref({})
const savingKey = ref(null)

const load = async () => {
  loading.value = true
  try {
    const data = await $api(`/workforce/config/${props.domain}`)
    rules.value = data.rules || []
    edits.value = Object.fromEntries(rules.value.map(r => [r.key, r.value]))
  }
  finally { loading.value = false }
}

const sourceColor = s => ({ market_law: 'error', convention: 'warning', jobdomain: 'info', company: 'primary', default: 'secondary', to_complete: 'secondary' }[s] || 'secondary')
const isBool = r => typeof r.value === 'boolean'

const save = async r => {
  savingKey.value = r.key
  try {
    const data = await $api(`/workforce/config/${props.domain}/${r.key}`, { method: 'PUT', body: { value: edits.value[r.key] } })
    rules.value = data.rules || rules.value
    edits.value = Object.fromEntries(rules.value.map(x => [x.key, x.value]))
    toast(t('hrSettings.ruleSaved'), 'success')
  }
  catch { /* 422 (non-favorable / mandatory) surfaced by $api */ }
  finally { savingKey.value = null }
}

onMounted(load)
</script>

<template>
  <div>
    <VSkeletonLoader
      v-if="loading"
      type="table-row@4"
    />
    <VTable
      v-else
      density="comfortable"
    >
      <thead>
        <tr>
          <th>{{ t('hrSettings.rule') }}</th>
          <th>{{ t('hrSettings.value') }}</th>
          <th>{{ t('hrSettings.source') }}</th>
          <th v-if="!readOnly" />
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="r in rules"
          :key="r.key"
        >
          <td>
            <div class="d-flex align-center gap-1">
              <VIcon
                v-if="r.mutability === 'mandatory'"
                icon="tabler-lock"
                size="16"
                class="text-disabled"
                :title="t('hrSettings.mandatory')"
              />
              <span>{{ t('hrSettings.rules.' + r.key, r.key) }}</span>
            </div>
            <div
              v-if="r.reference"
              class="text-caption text-disabled"
            >
              {{ r.reference }}
            </div>
          </td>
          <td style="min-inline-size: 180px;">
            <template v-if="readOnly || !r.editable">
              <span v-if="isBool(r)">{{ r.value ? t('common.yes') : t('common.no') }}</span>
              <span v-else>{{ r.value ?? '—' }}</span>
            </template>
            <template v-else>
              <VSwitch
                v-if="isBool(r)"
                v-model="edits[r.key]"
                density="compact"
                hide-details
              />
              <AppTextField
                v-else
                v-model="edits[r.key]"
                type="number"
                density="compact"
                hide-details
                style="max-inline-size: 140px;"
              />
            </template>
          </td>
          <td>
            <VChip
              :color="sourceColor(r.source)"
              size="x-small"
              variant="tonal"
            >
              {{ t('hrSettings.sources.' + r.source, r.source) }}
            </VChip>
            <VChip
              v-if="r.status === 'to_complete'"
              color="warning"
              size="x-small"
              variant="tonal"
              class="ms-1"
            >
              {{ t('hrSettings.toComplete') }}
            </VChip>
          </td>
          <td
            v-if="!readOnly"
            class="text-end"
          >
            <VBtn
              v-if="r.editable"
              size="small"
              variant="tonal"
              :loading="savingKey === r.key"
              :disabled="edits[r.key] === r.value"
              @click="save(r)"
            >
              {{ t('common.save') }}
            </VBtn>
            <VIcon
              v-else
              icon="tabler-lock"
              size="16"
              class="text-disabled"
            />
          </td>
        </tr>
      </tbody>
    </VTable>
  </div>
</template>
