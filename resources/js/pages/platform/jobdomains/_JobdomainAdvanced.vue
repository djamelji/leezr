<script setup>
// ADR-799 — section AVANCÉE (repliée) de l'onglet Général : diagnostic de compatibilité avec l'ancienne
// configuration, compatibilité d'une société existante (lecture), configuration historique (lecture),
// comparaison ancienne / nouvelle création (simulation annulée). Hors parcours courant.
import { $platformApi } from '@/utils/platformApi'
import { useAppToast } from '@/composables/useAppToast'

const props = defineProps({ profile: { type: Object, required: true }, jobdomainId: { type: Number, required: true } })
const emit = defineEmits(['changed'])
const { t } = useI18n()
const { toast } = useAppToast()
const profile = computed(() => props.profile)
const id = computed(() => props.jobdomainId)

const plan = ref(null)
const planLoading = ref(false)
const drafting = ref(null)
const reportCompany = ref(null)
const report = ref(null)
const reportLoading = ref(false)
const fromLegacy = ref(false)
const companyReq = ref(null)

const draftFromLegacy = async kind => {
  drafting.value = kind
  try {
    const res = await $platformApi(`/jobdomains/${id.value}/legacy-drafts`, { method: 'POST', body: { kind } })

    toast(t('jobdomainProfile.drafted', { family: res.preset.family_key, version: res.preset.version }), 'success')
    emit('changed')
  }
  catch (e) { toast(e?.data?.message || t('jobdomainProfile.error'), 'error') }
  finally { drafting.value = null }
}
const loadPlan = async () => {
  planLoading.value = true
  try { plan.value = await $platformApi(`/jobdomains/${id.value}/initialization-plan?from_legacy=${fromLegacy.value ? 1 : 0}`) }
  finally { planLoading.value = false }
}
const loadReport = async cid => {
  report.value = null
  if (!cid) return
  reportLoading.value = true
  try {
    [report.value, companyReq.value] = await Promise.all([
      $platformApi(`/companies/${cid}/jobdomain-compatibility`),
      $platformApi(`/companies/${cid}/company-requirements`).catch(() => null),
    ])
  }
  finally { reportLoading.value = false }
}
watch(reportCompany, loadReport)
const counts = f => profile.value.compatibility.families[f].counts
const classTone = { certain: 'text-success', partial: 'text-warning', legacy_only: 'text-info', preset_only: 'text-info' }
</script>

<template>
  <div>
    <!-- 4. Diagnostic de compatibilité -->
    <VCard
      class="mb-6"
      data-test="jd-compat"
    >
      <VCardItem
        :title="t('jobdomainProfile.compatTitle')"
        :subtitle="t('jobdomainProfile.compatHint')"
      />
      <VCardText>
        <div
          v-for="kind in ['rbac', 'requirements', 'planning']"
          :key="kind"
          class="jd-row"
          :data-test="`jd-compat-${kind}`"
        >
          <div class="flex-grow-1">
            <div class="text-body-1">
              {{ t(`jobdomainProfile.family.${kind}`) }}
            </div>
            <div class="text-body-2 text-medium-emphasis">
              <template v-if="profile.compatibility.families[kind].legacy_count === 0 && !profile.compatibility.families[kind].preset">
                {{ t('jobdomainProfile.noLegacy') }}
              </template>
              <template v-else>
                <span
                  v-for="c in ['certain', 'partial', 'legacy_only', 'preset_only']"
                  :key="c"
                  class="me-3"
                ><span :class="classTone[c]">{{ counts(kind)[c] }}</span> {{ t(`jobdomainProfile.class.${c}`) }}</span>
              </template>
              <template v-if="profile.compatibility.families[kind].skipped.length">
                · {{ t('jobdomainProfile.skipped', { n: profile.compatibility.families[kind].skipped.length }) }}
              </template>
            </div>
          </div>
          <VBtn
            v-if="counts(kind).legacy_only > 0"
            size="small"
            variant="tonal"
            :loading="drafting === kind"
            :data-test="`jd-draft-${kind}`"
            @click="draftFromLegacy(kind)"
          >
            {{ t('jobdomainProfile.draft') }}
          </VBtn>
        </div>
        <div
          v-if="profile.compatibility.role_prescriptions.items.length"
          class="jd-row"
        >
          <div class="flex-grow-1">
            <div class="text-body-1">
              {{ t('jobdomainProfile.prescriptionsTitle') }}
            </div>
            <div class="text-body-2 text-medium-emphasis">
              {{ t('jobdomainProfile.prescriptionsHint') }}
              {{ profile.compatibility.role_prescriptions.items.map(p => `${p.name} (${p.fields + p.documents})`).join(', ') }}
            </div>
          </div>
          <AppStatusText
            tone="warning"
            :text="t('jobdomainProfile.class.human_decision')"
          />
        </div>
        <p class="text-body-2 text-medium-emphasis mt-4 mb-0">
          {{ t('jobdomainProfile.enginesTitle') }} :
          {{ profile.compatibility.legacy_engines.map(e => t(`jobdomainProfile.engine.${e.engine}`)).join(' · ') }}.
          {{ profile.compatibility.versioned_init_enabled ? '' : t('jobdomainProfile.initNotOperational') }}
        </p>
      </VCardText>
    </VCard>

    <!-- 4 bis. Compatibilité d'une société existante (lecture seule) -->
    <VCard
      class="mb-6"
      data-test="jd-company-report"
    >
      <VCardItem
        :title="t('jobdomainProfile.reportTitle')"
        :subtitle="t('jobdomainProfile.reportHint')"
      />
      <VCardText>
        <AppSelect
          v-model="reportCompany"
          :items="profile.company_list.map(c => ({ value: c.id, title: `${c.name} (#${c.id})` }))"
          :loading="reportLoading"
          clearable
          :label="t('jobdomainProfile.reportCompany')"
          data-test="jd-report-company"
        />
        <template v-if="report">
          <div class="d-flex flex-wrap gap-4 mt-4">
            <div
              v-for="(n, c) in report.summary"
              :key="c"
              class="jd-stat"
            >
              <div class="text-h5">
                {{ n }}
              </div>
              <div class="text-body-2 text-medium-emphasis">
                {{ t(`jobdomainProfile.summary.${c}`) }}
              </div>
            </div>
          </div>
          <div class="jd-row mt-2">
            <div class="flex-grow-1 text-body-2">
              <div>{{ t('jobdomainProfile.reportPeople', report.people) }}</div>
              <div v-if="report.people.position_role_pairs.length">
                {{ t('jobdomainProfile.reportPairs') }} {{ report.people.position_role_pairs.map(p => `${p.pair} (${p.count})`).join(' · ') }}
              </div>
              <div>{{ t('jobdomainProfile.reportRequirements', { historical: report.requirements.historical, parallel: report.requirements.parallel_required, matches: report.requirements.matches, differences: report.requirements.differences }) }}</div>
              <div v-if="report.modules.ambiguous.length">
                {{ t('jobdomainProfile.reportModules', { list: report.modules.ambiguous.join(', ') }) }}
              </div>
            </div>
          </div>
          <VAlert
            type="info"
            variant="tonal"
            density="compact"
            class="mt-2"
          >
            {{ t('jobdomainProfile.reportGuarantee') }}
          </VAlert>
          <template v-if="companyReq">
            <div
              class="text-overline mt-4"
              data-test="jd-company-requirements"
            >
              {{ t('jobdomainProfile.companyReqTitle') }}
            </div>
            <div class="text-body-2 text-medium-emphasis mb-1">
              {{ companyReq.legal_representative ? t('jobdomainProfile.legalRep', { name: companyReq.legal_representative.name }) : t('jobdomainProfile.legalRepNone') }}
            </div>
            <div
              v-for="it in companyReq.items"
              :key="it.subject + it.kind + it.code"
              class="jd-row"
            >
              <div class="flex-grow-1">
                <div class="text-body-2">
                  {{ it.label }}<span
                    v-if="it.required"
                    class="text-error"
                  > *</span> · {{ t(`presetEditor.subject.${it.subject}`) }} · {{ it.kind === 'document' ? t('presetEditor.document') : t('presetEditor.field') }}
                </div>
                <div class="text-body-2 text-medium-emphasis">
                  {{ t('jobdomainProfile.why') }} {{ it.sources.map(src => src.type === 'registry' ? `${src.source} ${src.ref || ''} ${src.version ? 'v' + src.version : ''}`.trim() : t('jobdomainProfile.historicalActivation')).join(' · ') }}
                </div>
              </div>
              <AppStatusText
                :tone="it.state === 'provided' ? 'success' : (it.required ? 'warning' : 'info')"
                :text="t(`jobdomainProfile.reqState.${it.state}`)"
              />
            </div>
          </template>
        </template>
      </VCardText>
    </VCard>

    <!-- 5. Configuration historique — lecture seule -->
    <VExpansionPanels
      class="mb-6"
      data-test="jd-legacy"
    >
      <VExpansionPanel>
        <VExpansionPanelTitle>
          <div>
            <div class="text-body-1 font-weight-medium">
              {{ t('jobdomainProfile.legacyTitle') }}
            </div>
            <div class="text-body-2 text-medium-emphasis">
              {{ t('jobdomainProfile.legacyHint') }}
            </div>
          </div>
        </VExpansionPanelTitle>
        <VExpansionPanelText>
          <div class="text-overline">
            {{ t('jobdomainProfile.legacyRoles') }}
          </div>
          <div
            v-for="(r, key) in profile.legacy.roles"
            :key="key"
            class="jd-row"
          >
            <div class="flex-grow-1">
              <div class="text-body-1">
                {{ r.name }}
              </div>
              <div class="text-body-2 text-medium-emphasis">
                {{ key }} · {{ t('jobdomainProfile.bundles', { n: (r.bundles || []).length }) }}<template v-if="r.archetype">
                  · {{ r.archetype }}
                </template>
              </div>
            </div>
          </div>
          <div class="text-overline mt-4">
            {{ t('jobdomainProfile.legacyDocuments') }}
          </div>
          <div class="text-body-2">
            {{ profile.legacy.documents.map(d => d.code + (d.required ? ' *' : '')).join(', ') || '—' }}
          </div>
          <div class="text-overline mt-4">
            {{ t('jobdomainProfile.legacyFields') }}
          </div>
          <div class="text-body-2">
            {{ profile.legacy.fields.map(f => f.code).join(', ') || '—' }}
          </div>
          <div class="text-overline mt-4">
            {{ t('jobdomainProfile.legacyPlanning') }}
          </div>
          <div class="text-body-2">
            {{ Object.entries(profile.legacy.planning).filter(([k]) => k !== 'schema_version').map(([k, n]) => `${k} : ${n}`).join(' · ') || '—' }}
          </div>
        </VExpansionPanelText>
      </VExpansionPanel>
    </VExpansionPanels>

    <!-- 6. Aperçu d'une nouvelle société (non opérationnel) -->
    <VCard
      class="mb-6"
      data-test="jd-init"
    >
      <VCardItem
        :title="t('jobdomainProfile.initTitle')"
        :subtitle="t('jobdomainProfile.initHint')"
      />
      <VCardText v-if="plan">
        <div class="d-flex flex-wrap gap-4 mb-3">
          <div
            v-for="c in ['bugs', 'voluntary', 'configuration']"
            :key="c"
            class="jd-stat"
          >
            <div
              class="text-h5"
              :class="c === 'bugs' && plan.parity.bugs ? 'text-error' : ''"
            >
              {{ plan.parity[c] }}
            </div>
            <div class="text-body-2 text-medium-emphasis">
              {{ t(`jobdomainProfile.parityClass.${c}`) }}
            </div>
          </div>
        </div>
        <div class="text-body-2 text-medium-emphasis mb-2">
          {{ t('jobdomainProfile.parityRecs') }} {{ plan.parity.recommendations.join(', ') || '—' }}
        </div>
        <div
          v-for="(d, i) in plan.parity.differences.slice(0, 40)"
          :key="i"
          class="jd-row"
        >
          <div class="flex-grow-1 text-body-2">
            <span class="font-weight-medium">{{ t(`jobdomainProfile.dimension.${d.dimension}`, d.dimension) }}</span> · {{ d.item }}
            <div
              v-if="d.reason"
              class="text-medium-emphasis"
            >
              {{ t(`jobdomainProfile.voluntary.${d.reason}`, d.explanation) }}
            </div>
          </div>
          <AppStatusText
            :tone="d.class === 'bug' ? 'error' : (d.class === 'voluntary' ? 'info' : 'warning')"
            :text="t(`jobdomainProfile.parityClass.${d.class === 'bug' ? 'bugs' : d.class}`)"
          />
        </div>
        <div class="text-overline mt-4">
          {{ t('jobdomainProfile.modulesTitle') }}
        </div>
        <div class="text-body-2">
          <span
            v-for="m in plan.plan.modules"
            :key="m.key"
            class="me-3"
          >{{ m.name }} — {{ t(`jobdomainProfile.moduleClass.${m.class}`) }}</span>
        </div>
        <VAlert
          type="warning"
          variant="tonal"
          density="compact"
          class="mt-3"
        >
          {{ t('jobdomainProfile.noParity') }}
        </VAlert>
      </VCardText>
      <VCardActions class="justify-end flex-wrap">
        <VSwitch
          v-model="fromLegacy"
          hide-details
          density="compact"
          :label="t('jobdomainProfile.fromLegacy')"
          class="me-4"
          data-test="jd-from-legacy"
        />
        <VBtn
          v-platform-can="'manage_jobdomains'"
          variant="tonal"
          :loading="planLoading"
          data-test="jd-load-plan"
          @click="loadPlan"
        >
          {{ t('jobdomainProfile.loadPlan') }}
        </VBtn>
      </VCardActions>
    </VCard>
  </div>
</template>

<style scoped>
.jd { max-inline-size: 1100px; }
.jd-row { display: flex; align-items: center; gap: 12px; padding-block: 10px; flex-wrap: wrap; }
.jd-row + .jd-row { border-block-start: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.jd-stat { min-inline-size: 110px; }
.jd-preset { display: grid; grid-template-columns: minmax(200px, 1fr) minmax(240px, 1.4fr) auto; gap: 12px; align-items: center; padding-block: 12px; }
.jd-preset + .jd-preset { border-block-start: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
.jd-preset__edit { display: flex; gap: 8px; flex-wrap: wrap; }
.jd-preset__edit > * { flex: 1 1 160px; }
@media (max-width: 760px) { .jd-preset { grid-template-columns: 1fr; } }
</style>
