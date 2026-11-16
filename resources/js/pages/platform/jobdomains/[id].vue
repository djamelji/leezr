<script setup>
// ADR-797 (L7) — JobDomain = contexte métier + orchestrateur de recommandations.
// Identité · modules recommandés · presets versionnés recommandés · diagnostic de compatibilité ·
// configuration historique en LECTURE SEULE (encore lue par la création des sociétés) · aperçu d'une
// nouvelle société (non opérationnel). Aucun rôle, champ, document ou planning n'est édité ici.
import { $platformApi } from '@/utils/platformApi'
import { useAppToast } from '@/composables/useAppToast'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.jobdomains',
    permission: 'manage_jobdomains',
  },
})

const { t } = useI18n()
const route = useRoute()
const { toast } = useAppToast()

const FAMILIES = ['rbac', 'organization', 'requirements', 'planning']
const TERMS = ['employee', 'position', 'team', 'site']

const profile = ref(null)
const loading = ref(true)
const saving = ref(false)
const identity = ref({})
const modules = ref([])
const links = ref({}) // kind → { family_key, pinned_version }
const families = ref({}) // kind → [{ family_key, label, versions }]
const plan = ref(null)
const planLoading = ref(false)
const drafting = ref(null)
const reportCompany = ref(null)
const report = ref(null)
const reportLoading = ref(false)

const id = computed(() => Number(route.params.id))

const loadFamilies = async () => {
  const [presets, orgs] = await Promise.all([$platformApi('/presets'), $platformApi('/organization-presets?per_page=100').catch(() => ({ data: [] }))])
  const all = [...(presets.presets || []), ...(orgs.data || []).map(o => ({ ...o, kind: 'organization' }))]
  const out = {}
  for (const k of FAMILIES) {
    const byFamily = {}
    for (const p of all.filter(x => x.kind === k)) {
      byFamily[p.family_key] ??= { family_key: p.family_key, label: p.label, versions: [] }
      byFamily[p.family_key].versions.push({ version: p.version, status: p.status, published: !!p.published_at })
    }
    out[k] = Object.values(byFamily)
  }
  families.value = out
}

const load = async () => {
  loading.value = true
  try {
    profile.value = await $platformApi(`/jobdomains/${id.value}/profile`)
    identity.value = { ...profile.value.identity, terminology: { ...(profile.value.identity.terminology || {}) } }
    modules.value = profile.value.modules.map(m => m.key)
    links.value = Object.fromEntries(profile.value.recommendations.filter(r => !r.market_key).map(r => [r.kind, { family_key: r.family_key, pinned_version: r.pinned_version }]))
    await loadFamilies()
  }
  finally { loading.value = false }
}
onMounted(load)

const run = async (fn, okMessage) => {
  saving.value = true
  try {
    const res = await fn()

    toast(okMessage(res), 'success')
    await load()
  }
  catch (e) { toast(e?.data?.message || t('jobdomainProfile.error'), 'error') }
  finally { saving.value = false }
}

const saveIdentity = () => run(() => $platformApi(`/jobdomains/${id.value}`, { method: 'PUT', body: {
  label: identity.value.label, description: identity.value.description || null, sector: identity.value.sector || null,
  terminology: Object.fromEntries(Object.entries(identity.value.terminology).filter(([, v]) => v)),
} }), () => t('jobdomainProfile.saved'))

const saveModules = () => run(() => $platformApi(`/jobdomains/${id.value}`, { method: 'PUT', body: { default_modules: modules.value } }),
  res => (res.auto_added?.length ? t('jobdomainProfile.modulesSavedDeps', { list: res.auto_added.join(', ') }) : t('jobdomainProfile.saved')))

const saveRecommendations = () => run(() => {
  const kept = profile.value.recommendations.filter(r => r.market_key).map(r => ({ kind: r.kind, family_key: r.family_key, market_key: r.market_key, pinned_version: r.pinned_version }))
  const body = FAMILIES.filter(k => links.value[k]?.family_key).map(k => ({ kind: k, family_key: links.value[k].family_key, pinned_version: links.value[k].pinned_version || null }))

  return $platformApi(`/jobdomains/${profile.value.identity.key}/preset-recommendations`, { method: 'PUT', body: { links: [...body, ...kept] } })
}, () => t('jobdomainProfile.saved'))

const draftFromLegacy = async kind => {
  drafting.value = kind
  try {
    const res = await $platformApi(`/jobdomains/${id.value}/legacy-drafts`, { method: 'POST', body: { kind } })

    toast(t('jobdomainProfile.drafted', { family: res.preset.family_key, version: res.preset.version }), 'success')
    await load()
  }
  catch (e) { toast(e?.data?.message || t('jobdomainProfile.error'), 'error') }
  finally { drafting.value = null }
}

const fromLegacy = ref(false)
const loadPlan = async () => {
  planLoading.value = true
  try { plan.value = await $platformApi(`/jobdomains/${id.value}/initialization-plan?from_legacy=${fromLegacy.value ? 1 : 0}`) }
  finally { planLoading.value = false }
}
const companyReq = ref(null)

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

const recOf = kind => profile.value?.recommendations.find(r => r.kind === kind && !r.market_key)
const familyOptions = kind => (families.value[kind] || []).map(f => ({ value: f.family_key, title: f.label ? `${f.label} (${f.family_key})` : f.family_key }))
const versionOptions = kind => {
  const fam = (families.value[kind] || []).find(f => f.family_key === links.value[kind]?.family_key)

  return [{ value: null, title: t('jobdomainProfile.latestPublished') }, ...(fam?.versions || []).filter(v => v.published).map(v => ({ value: v.version, title: `v${v.version} · ${t(`jobdomainProfile.status.${v.status}`)}` }))]
}
const setFamily = (kind, family) => {
  const next = { ...links.value }

  family ? (next[kind] = { family_key: family, pinned_version: null }) : delete next[kind]
  links.value = next
}
const moduleItems = computed(() => (profile.value?.available_modules || []).map(m => ({ value: m.key, title: m.name + (m.min_plan ? ` · ${m.min_plan}` : '') })))
const counts = f => profile.value.compatibility.families[f].counts
const classTone = { certain: 'text-success', partial: 'text-warning', legacy_only: 'text-info', preset_only: 'text-info' }
</script>

<template>
  <div
    v-if="loading && !profile"
    class="d-flex justify-center pa-12"
  >
    <VProgressCircular indeterminate />
  </div>

  <div
    v-else-if="profile"
    class="jd"
  >
    <!-- En-tête -->
    <div class="d-flex flex-wrap align-center gap-3 mb-6">
      <div class="flex-grow-1">
        <RouterLink
          :to="{ name: 'platform-catalog-tab', params: { tab: 'jobdomains' } }"
          class="text-body-2"
        >
          {{ t('jobdomainProfile.back') }}
        </RouterLink>
        <h1 class="text-h4 mt-1">
          {{ profile.identity.label }}
        </h1>
        <div class="text-body-2 text-medium-emphasis">
          {{ profile.identity.key }} · {{ t('jobdomainProfile.companies', { n: profile.compatibility.companies }) }}
        </div>
      </div>
      <AppStatusText
        :tone="profile.identity.is_active ? 'success' : 'info'"
        :text="profile.identity.is_active ? t('jobdomainProfile.active') : t('jobdomainProfile.inactive')"
      />
    </div>

    <VAlert
      type="info"
      variant="tonal"
      class="mb-6"
    >
      {{ t('jobdomainProfile.doctrine') }}
    </VAlert>

    <!-- 1. Identité métier -->
    <VCard
      class="mb-6"
      data-test="jd-identity"
    >
      <VCardItem :title="t('jobdomainProfile.identityTitle')" />
      <VCardText>
        <VRow>
          <VCol
            cols="12"
            md="6"
          >
            <AppTextField
              v-model="identity.label"
              :label="t('jobdomainProfile.name')"
            />
          </VCol>
          <VCol
            cols="12"
            md="6"
          >
            <AppTextField
              v-model="identity.sector"
              :label="t('jobdomainProfile.sector')"
              data-test="jd-sector"
            />
          </VCol>
          <VCol cols="12">
            <AppTextarea
              v-model="identity.description"
              rows="2"
              :label="t('jobdomainProfile.description')"
            />
          </VCol>
          <VCol
            v-for="term in TERMS"
            :key="term"
            cols="12"
            sm="6"
            md="3"
          >
            <AppTextField
              v-model="identity.terminology[term]"
              :label="t(`jobdomainProfile.term.${term}`)"
              :placeholder="t(`jobdomainProfile.termDefault.${term}`)"
            />
          </VCol>
        </VRow>
        <div class="text-body-2 text-medium-emphasis mt-4 mb-1">
          {{ t('jobdomainProfile.markets') }}
        </div>
        <div class="d-flex flex-wrap gap-2">
          <VChip
            v-for="m in profile.markets"
            :key="m.key"
            size="small"
            :color="m.is_active ? 'primary' : undefined"
            variant="tonal"
          >
            {{ m.name }}<span v-if="m.has_override">&nbsp;· {{ t('jobdomainProfile.legacyOverride') }}</span>
          </VChip>
        </div>
      </VCardText>
      <VCardActions class="justify-end">
        <VBtn
          color="primary"
          :loading="saving"
          data-test="jd-save-identity"
          @click="saveIdentity"
        >
          {{ t('jobdomainProfile.save') }}
        </VBtn>
      </VCardActions>
    </VCard>

    <!-- 2. Modules recommandés -->
    <VCard class="mb-6">
      <VCardItem
        :title="t('jobdomainProfile.modulesTitle')"
        :subtitle="t('jobdomainProfile.modulesHint')"
      />
      <VCardText>
        <AppSelect
          v-model="modules"
          :items="moduleItems"
          multiple
          chips
          closable-chips
          :label="t('jobdomainProfile.modules')"
          class="mb-2"
        />
        <div
          v-for="m in profile.modules"
          :key="m.key"
          class="jd-row"
        >
          <div class="flex-grow-1">
            <div class="text-body-1">
              {{ m.name }}
            </div>
            <div class="text-body-2 text-medium-emphasis">
              {{ m.key }}<template v-if="m.requires.length">
                · {{ t('jobdomainProfile.requires', { list: m.requires.join(', ') }) }}
              </template>
            </div>
          </div>
          <AppStatusText
            :tone="{ core: 'success', included: 'success', paid_optional: 'info', incompatible: 'error', unavailable: 'warning' }[m.class] || 'info'"
            :text="t(`jobdomainProfile.moduleClass.${m.class}`) + (m.class === 'paid_optional' && m.min_plan ? ` · ${m.min_plan}` : '')"
          />
        </div>
      </VCardText>
      <VCardActions class="justify-end">
        <VBtn
          color="primary"
          variant="tonal"
          :loading="saving"
          @click="saveModules"
        >
          {{ t('jobdomainProfile.saveModules') }}
        </VBtn>
      </VCardActions>
    </VCard>

    <!-- 3. Presets recommandés -->
    <VCard
      class="mb-6"
      data-test="jd-presets"
    >
      <VCardItem
        :title="t('jobdomainProfile.presetsTitle')"
        :subtitle="t('jobdomainProfile.presetsHint')"
      />
      <VCardText>
        <div
          v-for="kind in FAMILIES"
          :key="kind"
          class="jd-preset"
          :data-test="`jd-preset-${kind}`"
        >
          <div>
            <div class="text-body-1 font-weight-medium">
              {{ t(`jobdomainProfile.family.${kind}`) }}
            </div>
            <div
              v-if="recOf(kind)"
              class="text-body-2 text-medium-emphasis"
            >
              {{ recOf(kind).label || recOf(kind).family_key }} ·
              <template v-if="recOf(kind).effective_version">
                v{{ recOf(kind).effective_version }} ({{ recOf(kind).pinned_version ? t('jobdomainProfile.pinned') : t('jobdomainProfile.latest') }})
              </template>
              <span
                v-else
                class="text-warning"
              >{{ t('jobdomainProfile.noPublished') }}</span>
              <template v-if="recOf(kind).newer_published">
                · <span class="text-warning">{{ t('jobdomainProfile.newer', { v: recOf(kind).newer_published }) }}</span>
              </template>
            </div>
            <div
              v-else
              class="text-body-2 text-medium-emphasis"
            >
              {{ t('jobdomainProfile.noRecommendation') }}
            </div>
          </div>
          <div class="jd-preset__edit">
            <AppSelect
              :model-value="links[kind]?.family_key || null"
              :items="familyOptions(kind)"
              clearable
              density="compact"
              :label="t('jobdomainProfile.presetFamily')"
              :no-data-text="t('jobdomainProfile.noFamily')"
              :data-test="`jd-family-${kind}`"
              @update:model-value="v => setFamily(kind, v)"
            />
            <AppSelect
              v-if="links[kind]?.family_key"
              v-model="links[kind].pinned_version"
              :items="versionOptions(kind)"
              density="compact"
              :label="t('jobdomainProfile.version')"
            />
          </div>
          <RouterLink
            :to="kind === 'organization' ? '/platform/organization-presets' : { name: 'platform-catalog-tab', params: { tab: 'presets' }, query: { kind } }"
            class="text-body-2"
          >
            {{ t('jobdomainProfile.manage') }}
          </RouterLink>
        </div>
      </VCardText>
      <VCardActions class="justify-end">
        <VBtn
          color="primary"
          :loading="saving"
          data-test="jd-save-presets"
          @click="saveRecommendations"
        >
          {{ t('jobdomainProfile.saveRecommendations') }}
        </VBtn>
      </VCardActions>
    </VCard>

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
