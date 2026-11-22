<script setup>
import AppPageHeader from '@/components/app/AppPageHeader.vue'
// ADR-821 — fiche MÉTIER simple, trois sections routées (ADR-475) :
//   Métier (identité, vocabulaire, expérience facultative) · Modules (compatibles, au démarrage, recommandés —
//   dépendances ajoutées automatiquement, droits commerciaux affichés) · Configuration initiale (bibliothèque de
//   départ et publication ; versions, autres familles et diagnostics en options avancées).
// Le métier ne possède ni les dépendances (modules), ni les droits (plans), ni les réglages Workforce.
import { $platformApi } from '@/utils/platformApi'
import { useAppToast } from '@/composables/useAppToast'
import { useCatalogText } from '@/composables/useCatalogText'
import JobdomainPresetTab from '../_JobdomainPresetTab.vue'
import JobdomainAdvanced from '../_JobdomainAdvanced.vue'
import JobdomainCompanyFields from '../_JobdomainCompanyFields.vue'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    module: 'platform.jobdomains',
    permission: 'manage_jobdomains',
    navActiveLink: 'platform-catalog-tab',
  },
})

const { t, te } = useI18n()
const route = useRoute()
const router = useRouter()
const { toast } = useAppToast()
const { moduleName } = useCatalogText()

const SECTIONS = [
  { key: 'general', icon: 'tabler-briefcase' },
  { key: 'modules', icon: 'tabler-puzzle' },
  { key: 'fields', icon: 'tabler-forms' }, // ADR-835 : champs demandés à la création d'une société
  { key: 'setup', icon: 'tabler-rocket' },
]
// Anciennes sections (ADR-820) → nouvelles
const LEGACY = { experience: 'general', presets: 'setup', publication: 'setup' }
const ADVANCED_FAMILIES = [
  { kind: 'rbac', icon: 'tabler-shield-lock' },
  { kind: 'organization', icon: 'tabler-sitemap' },
  { kind: 'requirements', icon: 'tabler-checklist' },
  { kind: 'planning', icon: 'tabler-calendar-time' },
]
const TERMS = ['employee', 'position', 'team', 'site']

const id = computed(() => Number(route.params.id))
const section = computed(() => (SECTIONS.some(s => s.key === route.params.tab) ? route.params.tab : 'general'))
if (!SECTIONS.some(s => s.key === route.params.tab))
  router.replace({ name: 'platform-jobdomains-id-tab', params: { id: route.params.id, tab: LEGACY[route.params.tab] || 'general' } })

const profile = ref(null)
watchEffect(() => { if (profile.value) document.title = `${profile.value.identity.label} · ${t(`jobdomainSimple.section.${section.value}`)} · ${t('l7.catalog.family')} · Leezr` }) // ADR-861 ; ADR-862 : after `profile` (read before its declaration, the watcher died in the TDZ)
const meta = ref(null)
const orgFamilies = ref([])
const loading = ref(true)
const saving = ref(false)
const identity = ref({})
const experience = ref({ group_order: [], entry_points: [] })
const refs = ref([])
const orgFamily = ref(null)
const family = ref('rbac')

// ── Composition des modules (ADR-821) ──
const composition = ref({ modules: [], plans: [] })
const compatible = ref([])
const start = ref([])
const recommended = ref([])
const loadComposition = async () => {
  composition.value = await $platformApi(`/jobdomains/${id.value}/composition`)
  const rows = composition.value.modules
  compatible.value = rows.filter(m => m.compatible).map(m => m.key)
  start.value = rows.filter(m => m.start && !m.start_dependency_of.length).map(m => m.key)
  recommended.value = rows.filter(m => m.recommended).map(m => m.key)
}

const load = async () => {
  loading.value = true
  try {
    const [p, m, orgs] = await Promise.all([
      $platformApi(`/jobdomains/${id.value}/profile`),
      meta.value ? Promise.resolve(meta.value) : $platformApi('/presets/meta'),
      $platformApi('/organization-presets?per_page=100&status=published').catch(() => ({ data: [] })),
      loadComposition(),
    ])

    profile.value = p
    meta.value = m
    orgFamilies.value = (orgs.data || []).filter(o => !o.jobdomain_key || o.jobdomain_key === p.identity.key)
    identity.value = { ...p.identity, terminology: { ...(p.identity.terminology || {}) } }
    experience.value = { group_order: [...(p.identity.experience?.group_order || [])], entry_points: [...(p.identity.experience?.entry_points || [])] }
    refs.value = [...(p.identity.convention_refs || [])]
    orgFamily.value = p.workspace.organization.published?.family_key || null
  }
  finally { loading.value = false }
}
onMounted(load)

const errorText = e => e?.data?.message || Object.values(e?.data?.errors || {})[0]?.[0] || t('jobdomainProfile.error')
const run = async (fn, ok) => {
  saving.value = true
  try {
    const res = await fn()

    toast(typeof ok === 'function' ? ok(res) : ok, 'success')
    await load()
  }
  catch (e) { toast(errorText(e), 'error') }
  finally { saving.value = false }
}
const put = body => $platformApi(`/jobdomains/${id.value}`, { method: 'PUT', body })

// ── Métier ──
const saveGeneral = () => run(() => put({
  label: identity.value.label, description: identity.value.description || null, sector: identity.value.sector || null,
  terminology: Object.fromEntries(Object.entries(identity.value.terminology).filter(([, v]) => v)),
  convention_refs: refs.value.filter(r => r.market_key && r.convention_collective_id),
}), t('jobdomainProfile.saved'))
const saveExperience = () => run(() => put({ experience: { group_order: experience.value.group_order, entry_points: experience.value.entry_points.slice(0, 6) } }), t('jobdomainProfile.saved'))
const conventionsFor = market => (profile.value?.conventions || []).filter(c => c.market_key === market)
  .map(c => ({ value: c.id, title: `${c.name} (IDCC ${c.idcc})${c.verified ? '' : ` — ${t('jobdomainTabs.unverified')}`}` }))
const activeMarkets = computed(() => (profile.value?.markets || []).filter(m => m.is_active))
const groupTitle = g => {
  const key = (profile.value?.nav_catalog || []).find(x => x.key === g)?.title_key
  if (g === 'main') return t('jobdomainExperience.mainGroup')

  return key && te(key) ? t(key) : (te(`nav.groups.${g}`) ? t(`nav.groups.${g}`) : g)
}
const itemTitle = i => (te(`nav.company.${i.key}`) ? t(`nav.company.${i.key}`) : i.title)
const groupItems = computed(() => (profile.value?.nav_catalog || []).map(g => ({ value: g.key, title: groupTitle(g.key) })))
const entryItems = computed(() => (profile.value?.nav_catalog || []).flatMap(g => g.items.map(i => ({ value: i.key, title: itemTitle(i), subtitle: groupTitle(g.key) }))))

// ── Modules : résolution locale des dépendances (déclarées par les modules, renvoyées par le serveur) ──
const byKey = computed(() => Object.fromEntries(composition.value.modules.map(m => [m.key, m])))
const closure = list => {
  const out = {}
  for (const k of list) for (const d of byKey.value[k]?.requires || []) if (!list.includes(d)) (out[d] ||= []).push(k)

  return out
}
const startDeps = computed(() => closure(start.value))
const recommendedDeps = computed(() => closure(recommended.value.filter(k => !start.value.includes(k) && !startDeps.value[k])))
const isStart = k => start.value.includes(k) || !!startDeps.value[k]
const isRecommended = k => !isStart(k) && (recommended.value.includes(k) || !!recommendedDeps.value[k])
const isCompatible = k => compatible.value.includes(k) || isStart(k) || isRecommended(k)
const toggle = (list, k, on) => {
  const i = list.indexOf(k)
  if (on && i < 0) list.push(k)
  if (!on && i >= 0) list.splice(i, 1)
}
const names = keys => keys.map(k => moduleName(k, byKey.value[k]?.name || k)).join(', ')
const COMMERCIAL_TONE = { included: 'success', with_dependency: 'info', option: 'warning', not_included: 'secondary' }
const startNotIncluded = computed(() => composition.value.modules.filter(m => isStart(m.key) && Object.values(m.commercial).some(s => s !== 'included')))
const MODULE_HEADERS = computed(() => [
  { title: t('jobdomainExperience.module'), key: 'name' },
  { title: t('jobdomainSimple.compatible'), key: 'compatible', width: 120, sortable: false },
  { title: t('jobdomainSimple.start'), key: 'start', width: 230, sortable: false },
  { title: t('jobdomainExperience.recommendedCol'), key: 'recommended', width: 130, sortable: false },
  { title: t('jobdomainSimple.offer'), key: 'commercial', width: 240, sortable: false },
])
const moduleSearch = ref('')
const moduleRows = computed(() => composition.value.modules.map(m => ({ ...m, name: moduleName(m.key, m.name) })))
const saveModules = () => run(() => $platformApi(`/jobdomains/${id.value}/composition`, { method: 'PUT', body: {
  compatible: composition.value.modules.filter(m => isCompatible(m.key)).map(m => m.key),
  start: start.value, recommended: recommended.value.filter(k => !isStart(k)),
} }), res => {
  const added = Object.entries(res.added || {})
  if (!added.length) return t('jobdomainProfile.saved')

  return added.map(([dep, by]) => t('jobdomainSimple.addedDependency', { modules: names(by), dependency: names([dep]) })).join(' ')
})

// ── Configuration initiale ──
const familyState = kind => profile.value?.workspace?.[kind]
const familyStatus = kind => {
  const s = familyState(kind)
  if (s?.draft) return { tone: 'warning', text: t('jobdomainExperience.draft') }
  if (s?.published) return { tone: 'success', text: t('jobdomainExperience.publishedV', { v: s.published.version }) }

  return { tone: 'secondary', text: t('jobdomainExperience.none') }
}
const linkFamily = (kind, familyKey) => run(() => {
  const links = profile.value.recommendations.filter(r => !(r.kind === kind && !r.market_key))
    .map(r => ({ kind: r.kind, family_key: r.family_key, market_key: r.market_key, pinned_version: r.pinned_version }))
  if (familyKey) links.push({ kind, family_key: familyKey })

  return $platformApi(`/jobdomains/${profile.value.identity.key}/preset-recommendations`, { method: 'PUT', body: { links } })
}, t('jobdomainProfile.saved'))
const saveOrganization = () => linkFamily('organization', orgFamily.value)
const orgStructure = computed(() => profile.value?.workspace.organization.published?.content?.structure || null)
const toggleFlag = (key, v) => run(() => put({ [key]: !!v }), t('jobdomainProfile.saved'))
const advanced = ref(null)
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
    data-test="jd-page"
  >
    <AppPageHeader
      :back="{ to: { name: 'platform-catalog-tab', params: { tab: 'jobdomains' } }, label: t('l7.catalog.family') }"
      :eyebrow="t('l7.jobdomain.eyebrow')"
      :title="profile.identity.label"
      :description="t('jobdomainProfile.companies', { n: profile.compatibility.companies })"
      data-test="jd-header"
    >
      <template #meta>
        <VChip
          :color="profile.identity.is_active ? 'success' : 'secondary'"
          variant="tonal"
          size="small"
          label
        >
          {{ profile.identity.is_active ? t('jobdomainProfile.active') : t('jobdomainProfile.inactive') }}
        </VChip>
        <VChip
          v-if="profile.identity.signup_available"
          color="primary"
          variant="tonal"
          size="small"
          label
        >
          {{ t('jobdomainExperience.openToSignup') }}
        </VChip>
      </template>
    </AppPageHeader>
    <VTabs
      :model-value="section"
      show-arrows
      class="border-b mb-6"
      data-test="jd-tabs"
    >
      <VTab
        v-for="s in SECTIONS"
        :key="s.key"
        :value="s.key"
        :to="{ name: 'platform-jobdomains-id-tab', params: { id: route.params.id, tab: s.key } }"
        :prepend-icon="s.icon"
        :data-test="`jd-tabbtn-${s.key}`"
      >
        {{ t(`jobdomainSimple.section.${s.key}`) }}
      </VTab>
    </VTabs>

    <!-- ── Métier ── -->
    <template v-if="section === 'general'">
      <VCard data-test="jd-identity">
        <VCardItem>
          <VCardTitle>{{ t('jobdomainExperience.identityTitle') }}</VCardTitle>
          <VCardSubtitle class="text-wrap">
            {{ t('jobdomainExperience.identityHint') }}
          </VCardSubtitle>
        </VCardItem>
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
          </VRow>
          <div class="text-body-1 font-weight-medium mt-4 mb-1">
            {{ t('jobdomainExperience.vocabulary') }}
          </div>
          <p class="text-body-2 text-medium-emphasis">
            {{ t('jobdomainExperience.vocabularyHint') }}
          </p>
          <VRow>
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
          <div class="text-body-1 font-weight-medium mt-6 mb-1">
            {{ t('jobdomainTabs.conventionTitle') }}
          </div>
          <p class="text-body-2 text-medium-emphasis">
            {{ t('jobdomainTabs.conventionHint') }}
          </p>
          <div
            v-for="m in activeMarkets"
            :key="m.key"
            class="mb-3"
            :data-test="`jd-conventions-${m.key}`"
          >
            <div
              v-for="(r, i) in refs.filter(x => x.market_key === m.key)"
              :key="i"
              class="d-flex gap-2 align-center mb-2"
            >
              <AppSelect
                v-model="r.convention_collective_id"
                :items="conventionsFor(m.key)"
                :label="`${t('jobdomainTabs.convention')} · ${m.name}`"
                :no-data-text="t('jobdomainTabs.noConvention')"
                class="flex-grow-1"
              />
              <IconBtn
                :aria-label="t('presetEditor.remove')"
                @click="refs.splice(refs.indexOf(r), 1)"
              >
                <VIcon icon="tabler-x" />
              </IconBtn>
            </div>
            <VBtn
              size="small"
              variant="text"
              prepend-icon="tabler-plus"
              :disabled="!conventionsFor(m.key).length"
              @click="refs.push({ market_key: m.key, convention_collective_id: null })"
            >
              {{ conventionsFor(m.key).length ? `${t('jobdomainTabs.addConvention')} (${m.name})` : t('jobdomainTabs.noConventionMarket') }}
            </VBtn>
          </div>
          <p class="text-body-2 text-medium-emphasis mb-0">
            {{ t('jobdomainTabs.notWorkPolicy') }}
          </p>
        </VCardText>
        <VCardActions class="justify-end">
          <VBtn
            color="primary"
            variant="elevated"
            :loading="saving"
            data-test="jd-save-general"
            @click="saveGeneral"
          >
            {{ t('jobdomainProfile.save') }}
          </VBtn>
        </VCardActions>
      </VCard>

      <VExpansionPanels class="mt-6">
        <VExpansionPanel data-test="jd-experience">
          <VExpansionPanelTitle data-test="jd-experience-toggle">
            <div>
              <div class="text-body-1 font-weight-medium">
                {{ t('jobdomainSimple.experienceTitle') }}
              </div>
              <div class="text-body-2 text-medium-emphasis">
                {{ t('jobdomainExperience.navHint') }}
              </div>
            </div>
          </VExpansionPanelTitle>
          <VExpansionPanelText>
            <AppAutocomplete
              v-model="experience.group_order"
              :items="groupItems"
              multiple
              chips
              closable-chips
              :label="t('jobdomainExperience.groupOrder')"
              :hint="t('jobdomainExperience.groupOrderHint')"
              persistent-hint
              class="mb-6"
              data-test="jd-group-order"
            />
            <AppAutocomplete
              v-model="experience.entry_points"
              :items="entryItems"
              item-props
              multiple
              chips
              closable-chips
              :counter="6"
              :label="t('jobdomainExperience.entryPoints')"
              :hint="t('jobdomainExperience.entryPointsHint')"
              persistent-hint
              data-test="jd-entry-points"
            />
            <div class="d-flex justify-end mt-4">
              <VBtn
                color="primary"
                :loading="saving"
                data-test="jd-save-experience"
                @click="saveExperience"
              >
                {{ t('jobdomainProfile.save') }}
              </VBtn>
            </div>
          </VExpansionPanelText>
        </VExpansionPanel>
      </VExpansionPanels>
    </template>

    <!-- ── Modules ── -->
    <template v-else-if="section === 'modules'">
      <VCard data-test="jd-modules">
        <VCardItem>
          <VCardTitle>{{ t('jobdomainExperience.modulesTitle') }}</VCardTitle>
          <template #append>
            <AppTextField
              v-model="moduleSearch"
              prepend-inner-icon="tabler-search"
              :placeholder="t('jobdomainExperience.search')"
              style="inline-size: 220px"
            />
          </template>
        </VCardItem>
        <VCardText class="text-body-2 text-medium-emphasis pb-2">
          {{ t('jobdomainSimple.modulesHint') }}
        </VCardText>
        <VCardText
          v-if="startNotIncluded.length"
          class="pt-0"
        >
          <VAlert
            type="info"
            variant="tonal"
            density="compact"
            data-test="jd-start-commercial"
          >
            {{ t('jobdomainSimple.startNotIncluded', { list: names(startNotIncluded.map(m => m.key)) }) }}
          </VAlert>
        </VCardText>
        <VDataTable
          :headers="MODULE_HEADERS"
          :items="moduleRows"
          :search="moduleSearch"
          :items-per-page="-1"
          hide-default-footer
          class="text-no-wrap"
          data-test="jd-modules-table"
        >
          <template #item.name="{ item }">
            <RouterLink
              :to="{ name: 'platform-modules-key', params: { key: item.key } }"
              class="font-weight-medium"
            >
              {{ item.name }}
            </RouterLink>
            <div
              v-if="item.requires.length"
              class="text-caption text-medium-emphasis"
            >
              {{ t('jobdomainSimple.needs', { list: names(item.requires) }) }}
            </div>
          </template>
          <template #item.compatible="{ item }">
            <VCheckbox
              :model-value="isCompatible(item.key)"
              :disabled="isStart(item.key) || isRecommended(item.key)"
              hide-details
              :data-test="`jd-compatible-${item.key}`"
              @update:model-value="v => toggle(compatible, item.key, v)"
            />
          </template>
          <template #item.start="{ item }">
            <div class="d-flex align-center gap-2">
              <VCheckbox
                :model-value="isStart(item.key)"
                :disabled="!!startDeps[item.key]"
                hide-details
                :data-test="`jd-include-${item.key}`"
                @update:model-value="v => { toggle(start, item.key, v); if (v) toggle(recommended, item.key, false) }"
              />
              <VChip
                v-if="startDeps[item.key]"
                size="x-small"
                color="info"
                variant="tonal"
                label
                class="text-wrap h-auto py-1"
                style="max-inline-size: 170px;"
                :data-test="`jd-dependency-${item.key}`"
              >
                {{ t('jobdomainSimple.requiredBy', { list: names(startDeps[item.key]) }) }}
              </VChip>
            </div>
          </template>
          <template #item.recommended="{ item }">
            <VCheckbox
              :model-value="isRecommended(item.key)"
              :disabled="isStart(item.key) || !!recommendedDeps[item.key]"
              hide-details
              :data-test="`jd-recommend-${item.key}`"
              @update:model-value="v => toggle(recommended, item.key, v)"
            />
          </template>
          <template #item.commercial="{ item }">
            <VChip
              v-if="new Set(Object.values(item.commercial)).size === 1"
              size="small"
              label
              variant="tonal"
              :color="COMMERCIAL_TONE[Object.values(item.commercial)[0]]"
            >
              {{ t(`jobdomainSimple.commercialAll.${Object.values(item.commercial)[0]}`) }}
            </VChip>
            <div
              v-else
              class="d-flex flex-wrap gap-1"
            >
              <VChip
                v-for="p in composition.plans"
                :key="p"
                size="x-small"
                label
                variant="tonal"
                :color="COMMERCIAL_TONE[item.commercial[p]]"
                :title="t(`jobdomainSimple.commercial.${item.commercial[p]}`)"
              >
                {{ p }} · {{ t(`jobdomainSimple.commercialShort.${item.commercial[p]}`) }}
              </VChip>
            </div>
          </template>
        </VDataTable>
        <VCardActions class="justify-end">
          <VBtn
            color="primary"
            variant="elevated"
            :loading="saving"
            data-test="jd-save-modules"
            @click="saveModules"
          >
            {{ t('jobdomainProfile.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </template>

    <!-- ── Champs de création société (ADR-835) ── -->
    <template v-else-if="section === 'fields'">
      <JobdomainCompanyFields :jobdomain-id="id" />
    </template>

    <!-- ── Configuration initiale ── -->
    <template v-else-if="section === 'setup'">
      <VRow>
        <VCol
          cols="12"
          lg="8"
        >
          <VCard data-test="jd-family-detail-catalog">
            <VCardItem>
              <template #prepend>
                <VAvatar
                  icon="tabler-books"
                  color="primary"
                  variant="tonal"
                  rounded
                />
              </template>
              <VCardTitle>{{ t('jobdomainExperience.family.catalog') }}</VCardTitle>
              <VCardSubtitle class="text-wrap">
                {{ t('jobdomainExperience.familyDetail.catalog') }}
              </VCardSubtitle>
            </VCardItem>
            <VCardText>
              <JobdomainPresetTab
                :jobdomain-id="id"
                kind="catalog"
                :state="familyState('catalog')"
                :meta="meta"
                @changed="load"
                @link="fk => linkFamily('catalog', fk)"
              />
            </VCardText>
          </VCard>
        </VCol>
        <VCol
          cols="12"
          lg="4"
        >
          <VCard
            class="mb-6"
            data-test="jd-publication"
          >
            <VCardItem>
              <VCardTitle>{{ t('jobdomainExperience.publicationTitle') }}</VCardTitle>
              <VCardSubtitle class="text-wrap">
                {{ t('jobdomainExperience.publicationHint') }}
              </VCardSubtitle>
            </VCardItem>
            <VList>
              <VListItem :title="t('jobdomainExperience.activeLabel')">
                <template #append>
                  <VSwitch
                    :model-value="!!profile.identity.is_active"
                    :disabled="saving"
                    hide-details
                    color="success"
                    data-test="jd-active"
                    @update:model-value="v => toggleFlag('is_active', v)"
                  />
                </template>
              </VListItem>
              <VListItem :title="t('jobdomainProfile.signupAvailable')">
                <template #append>
                  <VSwitch
                    :model-value="!!profile.identity.signup_available"
                    :disabled="saving || !profile.identity.is_active"
                    hide-details
                    color="success"
                    data-test="jd-signup-available"
                    @update:model-value="v => toggleFlag('signup_available', v)"
                  />
                </template>
              </VListItem>
            </VList>
          </VCard>
          <VCard data-test="jd-creation-path">
            <VCardItem>
              <VCardTitle>{{ t('jobdomainExperience.creationTitle') }}</VCardTitle>
              <VCardSubtitle class="text-wrap">
                {{ t('jobdomainExperience.creationHint') }}
              </VCardSubtitle>
            </VCardItem>
            <VList density="compact">
              <VListItem
                v-for="c in profile.creation"
                :key="c.market_key"
                :title="profile.markets.find(m => m.key === c.market_key)?.name || c.market_key"
              >
                <template #append>
                  <VChip
                    size="small"
                    label
                    :color="c.path === 'versioned' ? 'success' : 'warning'"
                    variant="tonal"
                    :data-test="`jd-creation-${c.market_key}`"
                  >
                    {{ t(`jobdomainExperience.path.${c.path}`) }}
                  </VChip>
                </template>
              </VListItem>
            </VList>
            <VCardText
              v-if="profile.creation.some(c => c.path === 'historical')"
              class="text-body-2 text-medium-emphasis"
            >
              {{ t('jobdomainExperience.historicalHint') }}
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <VExpansionPanels
        v-model="advanced"
        class="mt-6"
        data-test="jd-advanced"
      >
        <VExpansionPanel value="families">
          <VExpansionPanelTitle data-test="jd-advanced-families">
            <div>
              <div class="text-body-1 font-weight-medium">
                {{ t('jobdomainSimple.advancedFamilies') }}
              </div>
              <div class="text-body-2 text-medium-emphasis">
                {{ t('jobdomainSimple.advancedFamiliesHint') }}
              </div>
            </div>
          </VExpansionPanelTitle>
          <VExpansionPanelText>
            <VChipGroup
              v-model="family"
              mandatory
              selected-class="text-primary"
              class="mb-4"
            >
              <VChip
                v-for="f in ADVANCED_FAMILIES"
                :key="f.kind"
                :value="f.kind"
                :prepend-icon="f.icon"
                variant="tonal"
                label
                :data-test="`jd-family-${f.kind}`"
              >
                {{ t(`jobdomainExperience.family.${f.kind}`) }} · {{ familyStatus(f.kind).text }}
              </VChip>
            </VChipGroup>
            <p class="text-body-2 text-medium-emphasis">
              {{ t(`jobdomainExperience.familyDetail.${family}`) }}
            </p>
            <div
              v-if="family === 'organization'"
              class="d-flex flex-wrap gap-2 align-center"
            >
              <AppSelect
                v-model="orgFamily"
                :items="orgFamilies.map(o => ({ value: o.family_key, title: o.label || o.family_key }))"
                clearable
                :label="t('jobdomainTabs.organizationModel')"
                :no-data-text="t('jobdomainTabs.noOrganization')"
                style="flex: 1 1 280px"
              />
              <VBtn
                color="primary"
                :loading="saving"
                @click="saveOrganization"
              >
                {{ t('jobdomainProfile.save') }}
              </VBtn>
              <VBtn
                variant="tonal"
                prepend-icon="tabler-edit"
                :to="profile.workspace.organization.published ? `/platform/organization-presets/${profile.workspace.organization.published.id}` : '/platform/organization-presets'"
              >
                {{ t('jobdomainTabs.openOrganizationEditor') }}
              </VBtn>
              <span
                v-if="orgStructure"
                class="text-body-2 w-100"
              >{{ t('jobdomainExperience.orgSummary', { departments: (orgStructure.departments || []).length, positions: (orgStructure.job_roles || []).length }) }}</span>
            </div>
            <JobdomainPresetTab
              v-else
              :key="family"
              :jobdomain-id="id"
              :kind="family"
              :state="familyState(family)"
              :meta="meta"
              @changed="load"
              @link="fk => linkFamily(family, fk)"
            />
          </VExpansionPanelText>
        </VExpansionPanel>
        <VExpansionPanel value="diagnostics">
          <VExpansionPanelTitle data-test="jd-advanced-diagnostics">
            <div>
              <div class="text-body-1 font-weight-medium">
                {{ t('jobdomainTabs.advanced') }}
              </div>
              <div class="text-body-2 text-medium-emphasis">
                {{ t('jobdomainTabs.advancedHint') }}
              </div>
            </div>
          </VExpansionPanelTitle>
          <VExpansionPanelText>
            <JobdomainAdvanced
              :profile="profile"
              :jobdomain-id="id"
              @changed="load"
            />
          </VExpansionPanelText>
        </VExpansionPanel>
      </VExpansionPanels>
    </template>
  </div>
</template>
