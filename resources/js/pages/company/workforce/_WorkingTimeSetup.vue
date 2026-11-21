<script setup>
// ADR-799 — la SOCIÉTÉ précise comment elle organise le temps de travail (étape « Temps de travail » de
// l'assistant Workforce). A cadre applicable (convention CONFIRMÉE par la société, jamais déduite du
// métier) · B rythme · C repos · D horaires · E comportement. Valeurs légales et conventionnelles affichées
// avec leur référence quand elles sont vérifiées ; chaque réglage dit honnêtement s'il est contrôlé par le
// planning, seulement enregistré, ou si le contrôle juridique est indisponible. Aucun « conforme » affiché.
import { $api } from '@/utils/api'
import { useAppToast } from '@/composables/useAppToast'

// ADR-840 (M3.1, UXC-07) — readOnly : consultation (workforce.view without workforce.manage) — values shown,
// fields locked, no write command (the server refuses them anyway).
const props = defineProps({ readOnly: { type: Boolean, default: false } })
const emit = defineEmits(['saved', 'loaded'])
const { t } = useI18n()
const { toast } = useAppToast()

const data = ref(null)
const form = ref({})
const errors = ref({})
const busy = ref(false)
const convention = ref(null)
const section = ref('framework')

const load = async () => {
  data.value = await $api('/workforce/working-time-setup')
  // ADR-802 : clés gérées ici (source serveur) → la section « Réglages détaillés » ne les propose pas une 2ᵉ fois.
  emit('loaded', Object.values(data.value.sections).flat().map(x => x.key))
  const f = {}
  for (const list of Object.values(data.value.sections)) for (const field of list) f[field.key] = field.source === 'company' || field.key === 'week_start_day' ? field.value : null
  form.value = f
  convention.value = data.value.framework.current?.id ?? (data.value.framework.suggested[0]?.id ?? null)
}
onMounted(load)

const field = key => Object.values(data.value?.sections || {}).flat().find(f => f.key === key)
const effective = key => field(key)?.value
const legal = key => field(key)?.legal
const statusTone = s => ({ controlled: 'success', configured: 'info', not_set: 'info', legal_unverified: 'warning' }[s] || 'info')

const confirmConvention = async none => {
  busy.value = true
  try {
    data.value.framework = await $api('/workforce/working-time-setup/convention', { method: 'POST', body: { convention_collective_id: none ? null : convention.value } })
    toast(t('workingTime.frameworkConfirmed'), 'success')
  }
  catch (e) { toast(e?.data?.message || t('workingTime.error'), 'error') }
  finally { busy.value = false }
}

const save = async () => {
  busy.value = true
  errors.value = {}
  try {
    const values = Object.fromEntries(Object.entries(form.value).filter(([, v]) => v !== null && v !== '' && !(Array.isArray(v) && !v.length)))

    data.value = await $api('/workforce/working-time-setup', { method: 'PUT', body: { values } })
    toast(t('workingTime.saved'), 'success')
    emit('saved')
  }
  catch (e) {
    errors.value = e?.data?.errors || {}
    toast(e?.data?.message || t('workingTime.error'), 'error')
  }
  finally { busy.value = false }
}
const err = key => errors.value[key]?.[0]
const DAYS = [1, 2, 3, 4, 5, 6, 0]
const SECTIONS = ['framework', 'rhythm', 'rest', 'hours', 'behavior']
</script>

<template>
  <VForm
    v-if="data"
    :readonly="props.readOnly"
    data-test="working-time-setup"
    @submit.prevent
  >
    <VTabs
      v-model="section"
      show-arrows
      density="compact"
      class="mb-4"
    >
      <VTab
        v-for="(s, i) in SECTIONS"
        :key="s"
        :value="s"
        :data-test="`wt-section-${s}`"
      >
        {{ String.fromCharCode(65 + i) }}. {{ t(`workingTime.section.${s}`) }}
      </VTab>
    </VTabs>

    <!-- A. Cadre applicable -->
    <div v-if="section === 'framework'">
      <p class="text-body-2">
        {{ t('workingTime.frameworkIntro') }}
      </p>
      <VAlert
        :type="data.framework.legal_check === 'available' ? 'info' : 'warning'"
        variant="tonal"
        density="compact"
        class="mb-4"
        data-test="wt-legal-check"
      >
        {{ data.framework.legal_check === 'available' ? t('workingTime.legalAvailable', { market: data.framework.market, n: data.framework.legal_rules.length }) : t('workingTime.legalUnavailable') }}
      </VAlert>
      <AppSelect
        v-model="convention"
        :items="data.framework.available.map(c => ({ value: c.id, title: `${c.name} (IDCC ${c.idcc})${data.framework.suggested.some(s => s.id === c.id) ? ' — ' + t('workingTime.suggested') : ''}${c.verified ? '' : ' — ' + t('workingTime.unverified')}` }))"
        :label="t('workingTime.convention')"
        :no-data-text="t('workingTime.noConvention')"
        data-test="wt-convention"
      />
      <p
        v-if="convention && data.framework.available.find(c => c.id === convention) && !data.framework.available.find(c => c.id === convention).verified"
        class="text-body-2 text-warning mt-2"
      >
        {{ t('workingTime.conventionUnverified') }}
      </p>
      <div class="d-flex flex-wrap gap-2 mt-3 align-center">
        <VBtn
          v-if="!props.readOnly"
          color="primary"
          :disabled="!convention"
          :loading="busy"
          data-test="wt-confirm-convention"
          @click="confirmConvention(false)"
        >
          {{ t('workingTime.confirmConvention') }}
        </VBtn>
        <VBtn
          v-if="!props.readOnly"
          variant="text"
          :loading="busy"
          @click="confirmConvention(true)"
        >
          {{ t('workingTime.noneApplies') }}
        </VBtn>
        <span
          class="text-body-2"
          :class="data.framework.confirmed ? 'text-success' : 'text-medium-emphasis'"
          data-test="wt-framework-status"
        >
          {{ data.framework.confirmed ? t('workingTime.confirmedOn', { date: new Date(data.framework.confirmed_at).toLocaleDateString() }) : (data.framework.unconfirmed_legacy ? t('workingTime.legacyUnconfirmed') : t('workingTime.notConfirmed')) }}
        </span>
      </div>
      <p
        v-if="data.contracts.own_convention"
        class="text-body-2 text-medium-emphasis mt-3"
      >
        {{ t('workingTime.contractsOwnConvention', { n: data.contracts.own_convention }) }}
      </p>
    </div>

    <!-- B. Rythme -->
    <div v-else-if="section === 'rhythm'">
      <p class="text-body-2 text-medium-emphasis">
        {{ t('workingTime.rhythmIntro') }}
      </p>
      <div class="text-body-1 mb-1">
        {{ t('workingTime.f.work_days_per_week') }}
      </div>
      <VBtnToggle
        v-model="form.work_days_per_week"
        :disabled="props.readOnly"
        density="comfortable"
        variant="outlined"
        divided
        class="mb-4"
        data-test="wt-days-per-week"
      >
        <VBtn
          v-for="n in [4, 5, 6]"
          :key="n"
          :value="n"
        >
          {{ t('workingTime.daysPerWeek', { n }) }}
        </VBtn>
      </VBtnToggle>
      <div class="text-body-1 mb-1">
        {{ t('workingTime.f.fixed_work_days') }}
      </div>
      <div class="d-flex flex-wrap gap-1 mb-1">
        <VChip
          v-for="d in DAYS"
          :key="d"
          :color="(form.fixed_work_days || []).includes(d) ? 'primary' : undefined"
          variant="tonal"
          @click="form.fixed_work_days = (form.fixed_work_days || []).includes(d) ? form.fixed_work_days.filter(x => x !== d) : [...(form.fixed_work_days || []), d]"
        >
          {{ t(`workingTime.day.${d}`) }}
        </VChip>
      </div>
      <p class="text-caption text-medium-emphasis mb-4">
        {{ t('workingTime.variableHint') }}
      </p>
      <VRow>
        <VCol
          cols="12"
          sm="6"
        >
          <AppTextField
            v-model.number="form.weekly_hours_reference"
            type="number"
            :label="t('workingTime.f.weekly_hours_reference')"
            :placeholder="String(effective('weekly_hours_reference') ?? '')"
            :hint="legal('weekly_hours_reference') ? t('workingTime.legalRef', legal('weekly_hours_reference')) : ''"
            persistent-hint
            :error-messages="err('weekly_hours_reference')"
          />
        </VCol>
        <VCol
          cols="12"
          sm="6"
        >
          <AppSelect
            v-model="form.week_start_day"
            :items="DAYS.map(d => ({ value: d, title: t(`workingTime.day.${d}`) }))"
            clearable
            :label="t('workingTime.f.week_start_day')"
          />
        </VCol>
      </VRow>
      <p
        v-if="data.contracts.different_weekly_hours"
        class="text-body-2 text-medium-emphasis mt-2"
      >
        {{ t('workingTime.contractsDiffer', { n: data.contracts.different_weekly_hours }) }}
      </p>
    </div>

    <!-- C. Repos / D. Horaires : champs numériques expliqués -->
    <div v-else-if="section === 'rest' || section === 'hours'">
      <p class="text-body-2 text-medium-emphasis">
        {{ t(`workingTime.${section}Intro`) }}
      </p>
      <div
        v-for="f in data.sections[section].filter(x => !['consecutive_rest_days'].includes(x.key))"
        :key="f.key"
        class="wt-row"
        :data-test="`wt-field-${f.key}`"
      >
        <div class="wt-row__main">
          <div class="text-body-1">
            {{ t(`workingTime.f.${f.key}`) }}
          </div>
          <div class="text-body-2 text-medium-emphasis">
            <template v-if="f.legal">
              {{ t('workingTime.legalRef', f.legal) }} ·
            </template>
            {{ t('workingTime.effective', { value: f.value ?? '—' }) }}
            <template v-if="f.conflicts?.length">
              · <span class="text-warning">{{ t('workingTime.conflict') }}</span>
            </template>
          </div>
        </div>
        <AppTextField
          v-if="['night_work_start', 'night_work_end'].includes(f.key)"
          v-model="form[f.key]"
          type="time"
          density="compact"
          class="wt-input"
          :error-messages="err(f.key)"
        />
        <AppTextField
          v-else
          v-model.number="form[f.key]"
          type="number"
          density="compact"
          class="wt-input"
          :placeholder="f.value !== null && f.value !== undefined ? String(f.value) : ''"
          :error-messages="err(f.key)"
        />
        <AppStatusText
          :tone="statusTone(f.status)"
          :text="t(`workingTime.status.${f.status}`)"
        />
      </div>
      <VCheckbox
        v-if="section === 'rest'"
        v-model="form.consecutive_rest_days"
        :label="t('workingTime.f.consecutive_rest_days')"
        hide-details
      />
      <p
        v-if="section === 'hours'"
        class="text-body-2 text-medium-emphasis mt-3"
      >
        {{ t('workingTime.rotationsLater') }}
      </p>
    </div>

    <!-- E. Comportement -->
    <div v-else-if="section === 'behavior'">
      <p class="text-body-2 text-medium-emphasis">
        {{ t('workingTime.behaviorIntro') }}
      </p>
      <div
        class="wt-row"
        data-test="wt-behavior"
      >
        <div class="wt-row__main">
          <div class="text-body-1">
            {{ t('workingTime.f.consecutive_days_behavior') }}
          </div>
          <div class="text-body-2 text-medium-emphasis">
            {{ t('workingTime.behaviorConsecutiveHint') }}
          </div>
        </div>
        <AppSelect
          v-model="form.consecutive_days_behavior"
          :items="['inform', 'warn', 'block', 'approve'].map(b => ({ value: b, title: t(`workingTime.behavior.${b}`) }))"
          density="compact"
          class="wt-input-wide"
          :placeholder="t(`workingTime.behavior.${effective('consecutive_days_behavior') || 'warn'}`)"
        />
      </div>
      <div class="wt-row">
        <div class="wt-row__main">
          <div class="text-body-1">
            {{ t('workingTime.legalRules') }}
          </div>
          <div class="text-body-2 text-medium-emphasis">
            {{ t('workingTime.legalRulesHint') }}
          </div>
        </div>
        <AppStatusText
          tone="error"
          :text="t('workingTime.behavior.block')"
        />
      </div>
    </div>

    <div class="d-flex justify-end mt-6">
      <VBtn
        v-if="section !== 'framework' && !props.readOnly"
        color="primary"
        :loading="busy"
        data-test="wt-save"
        @click="save"
      >
        {{ t('workingTime.save') }}
      </VBtn>
    </div>
  </VForm>
  <div
    v-else
    class="d-flex justify-center pa-6"
  >
    <VProgressCircular indeterminate />
  </div>
</template>

<style scoped>
.wt-row { display: flex; align-items: center; gap: 12px; padding-block: 10px; flex-wrap: wrap; }
.wt-row + .wt-row { border-block-start: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.wt-row__main { flex: 1 1 260px; }
.wt-input { flex: 0 1 140px; }
.wt-input-wide { flex: 0 1 260px; }
</style>
