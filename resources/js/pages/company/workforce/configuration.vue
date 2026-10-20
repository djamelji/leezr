<script setup>
/**
 * Workforce configuration cockpit (Workforce 2.0 §8).
 * Shows each resolved rule with value, provenance, legal/configurable status, effective date and
 * an explanation. Configurable rules are editable; mandatory (legal) rules are read-only and the
 * API rejects any override. Driven entirely by the server WorkforceRuleResolver.
 */
import { $api } from '@/utils/api'
import { useAppToast } from '@/composables/useAppToast'

definePage({ meta: { module: 'workforce', permission: 'workforce.view' } })

const { t } = useI18n()
const { toast } = useAppToast()

const loading = ref(true)
const saving = ref(null)
const rules = ref([])
const edited = reactive({})

const sourceMeta = {
  market_law: { label: t('workforceConfig.sourceLaw'), color: 'error', icon: 'tabler-gavel' },
  company: { label: t('workforceConfig.sourceCompany'), color: 'primary', icon: 'tabler-building' },
  default: { label: t('workforceConfig.sourceDefault'), color: 'secondary', icon: 'tabler-adjustments' },
  to_complete: { label: t('workforceConfig.sourceToComplete'), color: 'warning', icon: 'tabler-alert-triangle' },
}
const mutabilityMeta = {
  mandatory: { label: t('workforceConfig.mandatory'), color: 'error' },
  configurable: { label: t('workforceConfig.configurable'), color: 'success' },
  to_complete: { label: t('workforceConfig.toComplete'), color: 'warning' },
}

// Rules shown as booleans / enums vs numeric minutes.
const BOOL_RULES = ['included_in_worktime', 'paid', 'splittable']
const ENUM_RULES = { enforcement: ['signaled', 'required'], early_resume_policy: ['allow', 'warn', 'block', 'approve'] }

function isBool(key) { return BOOL_RULES.includes(key) }
function isEnum(key) { return Object.keys(ENUM_RULES).includes(key) }
function enumOptions(key) { return (ENUM_RULES[key] || []).map(v => ({ title: t(`workforceConfig.enum.${v}`), value: v })) }

async function load() {
  loading.value = true
  try {
    const data = await $api('/workforce/config/breaks')
    rules.value = data.rules
    data.rules.forEach(r => { edited[r.key] = r.value })
  }
  catch (e) {
    toast(e.response?.data?.message || t('common.error'), 'error')
  }
  finally {
    loading.value = false
  }
}

async function save(rule) {
  saving.value = rule.key
  try {
    const data = await $api(`/workforce/config/breaks/${rule.key}`, { method: 'PUT', body: { value: edited[rule.key] } })
    rules.value = data.rules
    data.rules.forEach(r => { edited[r.key] = r.value })
    toast(t('workforceConfig.saved'), 'success')
  }
  catch (e) {
    toast(e.response?.data?.message || t('common.error'), 'error')
    await load()
  }
  finally {
    saving.value = null
  }
}

function explanation(rule) {
  if (rule.source === 'market_law') return t('workforceConfig.explainLaw', { ref: rule.reference || '' })
  if (rule.source === 'company') return t('workforceConfig.explainCompany')
  if (rule.source === 'to_complete') return t('workforceConfig.explainToComplete')

  return t('workforceConfig.explainDefault')
}

onMounted(load)
</script>

<template>
  <div>
    <div class="mb-6">
      <h4 class="text-h4 font-weight-bold">
        {{ t('workforceConfig.title') }}
      </h4>
      <p class="text-body-1 text-medium-emphasis mb-0">
        {{ t('workforceConfig.subtitle') }}
      </p>
    </div>

    <VCard :loading="loading">
      <VCardItem>
        <VCardTitle>{{ t('workforceConfig.breaksSection') }}</VCardTitle>
      </VCardItem>
      <VDivider />

      <VTable v-if="!loading" data-test="wf-config-breaks">
        <thead>
          <tr>
            <th>{{ t('workforceConfig.rule') }}</th>
            <th>{{ t('workforceConfig.value') }}</th>
            <th>{{ t('workforceConfig.provenance') }}</th>
            <th>{{ t('workforceConfig.statusCol') }}</th>
            <th>{{ t('workforceConfig.effectiveFrom') }}</th>
            <th class="text-end">
              {{ t('common.actions') }}
            </th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="rule in rules"
            :key="rule.key"
            :data-test="`wf-rule-${rule.key}`"
          >
            <td>
              <div class="font-weight-medium">
                {{ t(`workforceConfig.rules.${rule.key}`) }}
              </div>
              <div class="text-caption text-medium-emphasis">
                {{ explanation(rule) }}
              </div>
            </td>
            <td style="min-inline-size: 180px;">
              <!-- Configurable: inline editor; mandatory/read-only: plain value -->
              <template v-if="rule.editable">
                <VSwitch
                  v-if="isBool(rule.key)"
                  v-model="edited[rule.key]"
                  density="compact"
                  hide-details
                  :data-test="`wf-edit-${rule.key}`"
                />
                <AppSelect
                  v-else-if="isEnum(rule.key)"
                  v-model="edited[rule.key]"
                  :items="enumOptions(rule.key)"
                  density="compact"
                  hide-details
                  :data-test="`wf-edit-${rule.key}`"
                />
                <AppTextField
                  v-else
                  v-model.number="edited[rule.key]"
                  type="number"
                  density="compact"
                  hide-details
                  :placeholder="t('workforceConfig.minutes')"
                  :data-test="`wf-edit-${rule.key}`"
                />
              </template>
              <span
                v-else
                class="font-weight-bold"
                :data-test="`wf-value-${rule.key}`"
              >
                {{ rule.value === null ? '—' : rule.value }}
              </span>
            </td>
            <td>
              <VChip
                :color="sourceMeta[rule.source]?.color"
                size="small"
                variant="tonal"
              >
                <VIcon :icon="sourceMeta[rule.source]?.icon" size="14" start />
                {{ sourceMeta[rule.source]?.label }}
              </VChip>
              <div
                v-if="rule.reference"
                class="text-caption text-medium-emphasis mt-1"
              >
                {{ rule.reference }}
              </div>
            </td>
            <td>
              <VChip
                :color="mutabilityMeta[rule.mutability]?.color"
                size="small"
                variant="outlined"
                :data-test="`wf-mutability-${rule.key}`"
              >
                {{ mutabilityMeta[rule.mutability]?.label }}
              </VChip>
            </td>
            <td class="text-body-2 text-medium-emphasis">
              {{ rule.effective_from || '—' }}
            </td>
            <td class="text-end">
              <VBtn
                v-if="rule.editable"
                size="small"
                variant="tonal"
                :loading="saving === rule.key"
                :data-test="`wf-save-${rule.key}`"
                @click="save(rule)"
              >
                {{ t('common.save') }}
              </VBtn>
              <VIcon
                v-else
                icon="tabler-lock"
                size="18"
                class="text-disabled"
              />
            </td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
