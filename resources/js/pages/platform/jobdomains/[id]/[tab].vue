<script setup>
// ADR-820 — fiche JobDomain = PROFIL D'EXPÉRIENCE MÉTIER, une entrée unique en cinq sections routées
// (ADR-475 : l'onglet est dans le chemin) : Général · Modules · Expérience · Presets · Publication.
// Le domaine assemble des fonctionnalités existantes ; il ne crée aucune exigence. Les réglages propres aux
// modules restent dans les modules (le preset « Planning » n'en fournit que des valeurs initiales).
import { $platformApi } from '@/utils/platformApi'
import { useAppToast } from '@/composables/useAppToast'
import { useCatalogText } from '@/composables/useCatalogText'
import JobdomainPresetTab from '../_JobdomainPresetTab.vue'
import JobdomainAdvanced from '../_JobdomainAdvanced.vue'

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
  { key: 'general', icon: 'tabler-id' },
  { key: 'modules', icon: 'tabler-puzzle' },
  { key: 'experience', icon: 'tabler-layout-sidebar' },
  { key: 'presets', icon: 'tabler-stack-2' },
  { key: 'publication', icon: 'tabler-rocket' },
]
const FAMILIES = [
  { kind: 'catalog', icon: 'tabler-books' },
  { kind: 'rbac', icon: 'tabler-shield-lock' },
  { kind: 'organization', icon: 'tabler-sitemap' },
  { kind: 'requirements', icon: 'tabler-checklist' },
  { kind: 'planning', icon: 'tabler-calendar-time' },
]
const TERMS = ['employee', 'position', 'team', 'site']

const id = computed(() => Number(route.params.id))
const section = computed(() => (SECTIONS.some(s => s.key === route.params.tab) ? route.params.tab : 'general'))
if (!SECTIONS.some(s => s.key === route.params.tab)) router.replace({ name: 'platform-jobdomains-id-tab', params: { id: route.params.id, tab: 'general' } })

const profile = ref(null)
const meta = ref(null)
const orgFamilies = ref([])
const loading = ref(true)
const saving = ref(false)
const identity = ref({})
const included = ref([])
const recommended = ref([])
const experience = ref({ group_order: [], entry_points: [] })
const refs = ref([])
const orgFamily = ref(null)
const family = ref('catalog')

const load = async () => {
  loading.value = true
  try {
    const [p, m, orgs] = await Promise.all([
      $platformApi(`/jobdomains/${id.value}/profile`),
      meta.value ? Promise.resolve(meta.value) : $platformApi('/presets/meta'),
      $platformApi('/organization-presets?per_page=100&status=published').catch(() => ({ data: [] })),
    ])

    profile.value = p
    meta.value = m
    orgFamilies.value = (orgs.data || []).filter(o => !o.jobdomain_key || o.jobdomain_key === p.identity.key)
    identity.value = { ...p.identity, terminology: { ...(p.identity.terminology || {}) } }
    included.value = p.modules.map(x => x.key)
    recommended.value = [...(p.identity.recommended_modules || [])]
    experience.value = { group_order: [...(p.identity.experience?.group_order || [])], entry_points: [...(p.identity.experience?.entry_points || [])] }
    refs.value = [...(p.identity.convention_refs || [])]
    orgFamily.value = p.workspace.organization.published?.family_key || null
  }
  finally { loading.value = false }
}
onMounted(load)

const run = async (fn, ok) => {
  saving.value = true
  try {
    const res = await fn()

    toast(typeof ok === 'function' ? ok(res) : ok, 'success')
    await load()
  }
  catch (e) { toast(e?.data?.message || Object.values(e?.data?.errors || {})[0]?.[0] || t('jobdomainProfile.error'), 'error') }
  finally { saving.value = false }
}
const put = body => $platformApi(`/jobdomains/${id.value}`, { method: 'PUT', body })

const saveGeneral = () => run(() => put({
  label: identity.value.label, description: identity.value.description || null, sector: identity.value.sector || null,
  convention_refs: refs.value.filter(r => r.market_key && r.convention_collective_id),
}), t('jobdomainProfile.saved'))
const saveModules = () => run(() => put({ default_modules: included.value, recommended_modules: recommended.value.filter(k => !included.value.includes(k)) }),
  res => (res.auto_added?.length ? t('jobdomainProfile.modulesSavedDeps', { list: res.auto_added.join(', ') }) : t('jobdomainProfile.saved')))
const saveExperience = () => run(() => put({
  experience: { group_order: experience.value.group_order, entry_points: experience.value.entry_points.slice(0, 6) },
  terminology: Object.fromEntries(Object.entries(identity.value.terminology).filter(([, v]) => v)),
}), t('jobdomainProfile.saved'))
const toggle = (key, v) => run(() => put({ [key]: !!v }), t('jobdomainProfile.saved'))
const linkFamily = (kind, familyKey) => run(() => {
  const links = profile.value.recommendations.filter(r => !(r.kind === kind && !r.market_key))
    .map(r => ({ kind: r.kind, family_key: r.family_key, market_key: r.market_key, pinned_version: r.pinned_version }))
  if (familyKey) links.push({ kind, family_key: familyKey })

  return $platformApi(`/jobdomains/${profile.value.identity.key}/preset-recommendations`, { method: 'PUT', body: { links } })
}, t('jobdomainProfile.saved'))

// ── Général ──
const conventionsFor = market => (profile.value?.conventions || []).filter(c => c.market_key === market)
  .map(c => ({ value: c.id, title: `${c.name} (IDCC ${c.idcc})${c.verified ? '' : ` — ${t('jobdomainTabs.unverified')}`}` }))
const activeMarkets = computed(() => (profile.value?.markets || []).filter(m => m.is_active))

// ── Modules ──
const CLASS_TONE = { core: 'success', included: 'success', paid_optional: 'info', incompatible: 'error', unavailable: 'warning' }
const MODULE_HEADERS = computed(() => [
  { title: t('jobdomainExperience.module'), key: 'name' },
  { title: t('jobdomainExperience.compatibility'), key: 'compatible', width: 150 },
  { title: t('jobdomainExperience.includedCol'), key: 'included', width: 150, sortable: false },
  { title: t('jobdomainExperience.recommendedCol'), key: 'recommended', width: 150, sortable: false },
])
const moduleSearch = ref('')
const moduleRows = computed(() => (profile.value?.available_modules || []).filter(m => !m.core).map(m => ({ ...m, name: moduleName(m.key, m.name),
  requiresNames: (m.requires || []).map(r => moduleName(r, r)), cls: profile.value.modules.find(x => x.key === m.key)?.class || null })))
const coreModules = computed(() => (profile.value?.available_modules || []).filter(m => m.core).map(m => ({ ...m, name: moduleName(m.key, m.name) })))
// Le template reçoit les tableaux déjà déballés : on modifie le tableau lui-même (pas `.value`).
const setIn = (list, key, on) => {
  const i = list.indexOf(key)
  if (on && i < 0) list.push(key)
  if (!on && i >= 0) list.splice(i, 1)
}

// ── Expérience ──
const groupTitle = g => {
  const key = (profile.value?.nav_catalog || []).find(x => x.key === g)?.title_key

  if (g === 'main') return t('jobdomainExperience.mainGroup')

  return key && te(key) ? t(key) : (te(`nav.groups.${g}`) ? t(`nav.groups.${g}`) : g)
}
const itemTitle = i => (te(`nav.company.${i.key}`) ? t(`nav.company.${i.key}`) : i.title)
const groupItems = computed(() => (profile.value?.nav_catalog || []).map(g => ({ value: g.key, title: groupTitle(g.key) })))
const entryItems = computed(() => (profile.value?.nav_catalog || []).flatMap(g => g.items
  .filter(i => included.value.includes(i.module) || i.module.startsWith('core.') || recommended.value.includes(i.module))
  .map(i => ({ value: i.key, title: itemTitle(i), subtitle: groupTitle(g.key) }))))
const previewGroups = computed(() => {
  const cat = profile.value?.nav_catalog || []
  const order = [...experience.value.group_order, ...cat.map(g => g.key).filter(k => !experience.value.group_order.includes(k))]

  return order.map(k => cat.find(g => g.key === k)).filter(Boolean).slice(0, 4).map(g => ({ key: g.key, title: groupTitle(g.key),
    items: [...g.items].sort((a, b) => (experience.value.entry_points.includes(b.key) ? 1 : 0) - (experience.value.entry_points.includes(a.key) ? 1 : 0)).slice(0, 4) }))
})

// ── Presets ──
const familyState = kind => profile.value?.workspace?.[kind]
const familyStatus = kind => {
  const s = familyState(kind)
  if (s?.draft) return { tone: 'warning', text: t('jobdomainExperience.draft') }
  if (s?.published) return { tone: 'success', text: t('jobdomainExperience.publishedV', { v: s.published.version }) }

  return { tone: 'secondary', text: t('jobdomainExperience.none') }
}
const orgStructure = computed(() => profile.value?.workspace.organization.published?.content?.structure || null)
const saveOrganization = () => linkFamily('organization', orgFamily.value)

// ── Publication ──
const creationTone = p => (p === 'versioned' ? 'success' : 'warning')
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
    <div class="d-flex flex-wrap align-center gap-3 mb-6">
      <div class="flex-grow-1">
        <RouterLink
          :to="{ name: 'platform-catalog-tab', params: { tab: 'jobdomains' } }"
          class="text-body-2 d-inline-flex align-center gap-1"
        >
          {{ t('jobdomainProfile.back') }}
        </RouterLink>
        <h4 class="text-h4 mt-1">
          {{ profile.identity.label }}
        </h4>
        <div class="text-body-2 text-medium-emphasis">
          {{ t('jobdomainProfile.companies', { n: profile.compatibility.companies }) }}
        </div>
      </div>
      <VChip
        :color="profile.identity.is_active ? 'success' : 'secondary'"
        variant="tonal"
        label
      >
        {{ profile.identity.is_active ? t('jobdomainProfile.active') : t('jobdomainProfile.inactive') }}
      </VChip>
      <VChip
        v-if="profile.identity.signup_available"
        color="primary"
        variant="tonal"
        label
      >
        {{ t('jobdomainExperience.openToSignup') }}
      </VChip>
    </div>

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
        {{ t(`jobdomainExperience.section.${s.key}`) }}
      </VTab>
    </VTabs>

    <!-- ── Général ── -->
    <template v-if="section === 'general'">
      <VCard data-test="jd-identity">
        <VCardItem>
          <VCardTitle>{{ t('jobdomainExperience.identityTitle') }}</VCardTitle>
          <VCardSubtitle class="text-wrap">{{ t('jobdomainExperience.identityHint') }}</VCardSubtitle>
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
        </VCardText>
        <VDivider />
        <VCardItem>
          <VCardTitle class="text-body-1 font-weight-medium">
            {{ t('jobdomainTabs.conventionTitle') }}
          </VCardTitle>
          <VCardSubtitle class="text-wrap">{{ t('jobdomainTabs.conventionHint') }}</VCardSubtitle>
        </VCardItem>
        <VCardText>
          <div
            v-for="m in activeMarkets"
            :key="m.key"
            class="mb-3"
            :data-test="`jd-conventions-${m.key}`"
          >
            <div class="text-body-2 text-medium-emphasis mb-1">
              {{ m.name }}
            </div>
            <div
              v-for="(r, i) in refs.filter(x => x.market_key === m.key)"
              :key="i"
              class="d-flex gap-2 align-center mb-2"
            >
              <AppSelect
                v-model="r.convention_collective_id"
                :items="conventionsFor(m.key)"
                :label="t('jobdomainTabs.convention')"
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
              {{ conventionsFor(m.key).length ? t('jobdomainTabs.addConvention') : t('jobdomainTabs.noConventionMarket') }}
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
          {{ t('jobdomainExperience.modulesHint') }}
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
              v-if="item.requires?.length"
              class="text-caption text-medium-emphasis"
            >
              {{ t('jobdomainProfile.requires', { list: item.requiresNames.join(', ') }) }}
            </div>
          </template>
          <template #item.compatible="{ item }">
            <VChip
              size="small"
              label
              :color="item.compatible ? 'success' : 'error'"
              variant="tonal"
            >
              {{ item.compatible ? t('jobdomainExperience.compatible') : t('jobdomainExperience.incompatible') }}
            </VChip>
          </template>
          <template #item.included="{ item }">
            <VCheckbox
              :model-value="included.includes(item.key)"
              :disabled="!item.compatible"
              hide-details
              :data-test="`jd-include-${item.key}`"
              @update:model-value="v => { setIn(included, item.key, v); if (v) setIn(recommended, item.key, false) }"
            />
            <div
              v-if="item.cls === 'paid_optional'"
              class="text-caption text-info"
            >
              {{ t('jobdomainProfile.moduleClass.paid_optional') }}
            </div>
          </template>
          <template #item.recommended="{ item }">
            <VCheckbox
              :model-value="recommended.includes(item.key)"
              :disabled="!item.compatible || included.includes(item.key)"
              hide-details
              :data-test="`jd-recommend-${item.key}`"
              @update:model-value="v => setIn(recommended, item.key, v)"
            />
          </template>
        </VDataTable>
        <VCardText class="text-body-2 text-medium-emphasis pt-4">
          {{ t('jobdomainExperience.coreModules', { list: coreModules.map(m => m.name).join(', ') }) }}
        </VCardText>
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

    <!-- ── Expérience ── -->
    <template v-else-if="section === 'experience'">
      <VRow>
        <VCol
          cols="12"
          md="7"
        >
          <VCard data-test="jd-experience">
            <VCardItem>
              <VCardTitle>{{ t('jobdomainExperience.navTitle') }}</VCardTitle>
              <VCardSubtitle class="text-wrap">{{ t('jobdomainExperience.navHint') }}</VCardSubtitle>
            </VCardItem>
            <VCardText>
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
            </VCardText>
            <VDivider />
            <VCardItem>
              <VCardTitle class="text-body-1 font-weight-medium">
                {{ t('jobdomainExperience.vocabulary') }}
              </VCardTitle>
              <VCardSubtitle class="text-wrap">{{ t('jobdomainExperience.vocabularyHint') }}</VCardSubtitle>
            </VCardItem>
            <VCardText>
              <VRow>
                <VCol
                  v-for="term in TERMS"
                  :key="term"
                  cols="12"
                  sm="6"
                >
                  <AppTextField
                    v-model="identity.terminology[term]"
                    :label="t(`jobdomainProfile.term.${term}`)"
                    :placeholder="t(`jobdomainProfile.termDefault.${term}`)"
                  />
                </VCol>
              </VRow>
            </VCardText>
            <VCardActions class="justify-end">
              <VBtn
                color="primary"
                variant="elevated"
                :loading="saving"
                data-test="jd-save-experience"
                @click="saveExperience"
              >
                {{ t('jobdomainProfile.save') }}
              </VBtn>
            </VCardActions>
          </VCard>
        </VCol>
        <VCol
          cols="12"
          md="5"
        >
          <VCard
            variant="outlined"
            data-test="jd-experience-preview"
          >
            <VCardItem>
              <VCardTitle class="text-body-1 font-weight-medium">
                {{ t('jobdomainExperience.preview') }}
              </VCardTitle>
              <VCardSubtitle class="text-wrap">{{ t('jobdomainExperience.previewHint') }}</VCardSubtitle>
            </VCardItem>
            <VList
              density="compact"
              nav
            >
              <template
                v-for="g in previewGroups"
                :key="g.key"
              >
                <VListSubheader>{{ g.title }}</VListSubheader>
                <VListItem
                  v-for="i in g.items"
                  :key="i.key"
                  :title="itemTitle(i)"
                >
                  <template
                    v-if="experience.entry_points.includes(i.key)"
                    #append
                  >
                    <VIcon
                      icon="tabler-star-filled"
                      size="14"
                      color="warning"
                    />
                  </template>
                </VListItem>
              </template>
            </VList>
          </VCard>
        </VCol>
      </VRow>
    </template>

    <!-- ── Presets ── -->
    <template v-else-if="section === 'presets'">
      <p class="text-body-1 mb-4">
        {{ t('jobdomainExperience.presetsHint') }}
      </p>
      <VRow class="card-grid card-grid-sm mb-6">
        <VCol
          v-for="f in FAMILIES"
          :key="f.kind"
          cols="12"
          sm="6"
          lg="4"
        >
          <VCard
            :variant="family === f.kind ? 'outlined' : 'elevated'"
            :class="{ 'border-primary': family === f.kind }"
            :data-test="`jd-family-${f.kind}`"
            @click="family = f.kind"
          >
            <VCardItem>
              <template #prepend>
                <VAvatar
                  :icon="f.icon"
                  color="primary"
                  variant="tonal"
                  rounded
                />
              </template>
              <VCardTitle>{{ t(`jobdomainExperience.family.${f.kind}`) }}</VCardTitle>
              <VCardSubtitle class="text-wrap">{{ t(`jobdomainExperience.familyHint.${f.kind}`) }}</VCardSubtitle>
            </VCardItem>
            <VCardText>
              <VChip
                size="small"
                label
                :color="familyStatus(f.kind).tone"
                variant="tonal"
              >
                {{ familyStatus(f.kind).text }}
              </VChip>
            </VCardText>
          </VCard>
        </VCol>
      </VRow>

      <VCard :data-test="`jd-family-detail-${family}`">
        <VCardItem>
          <VCardTitle>{{ t(`jobdomainExperience.family.${family}`) }}</VCardTitle>
          <VCardSubtitle class="text-wrap">{{ t(`jobdomainExperience.familyDetail.${family}`) }}</VCardSubtitle>
        </VCardItem>
        <VCardText v-if="family === 'organization'">
          <div class="d-flex flex-wrap gap-2 align-center mb-4">
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
          </div>
          <p
            v-if="orgStructure"
            class="text-body-2 mb-0"
          >
            {{ t('jobdomainExperience.orgSummary', { departments: (orgStructure.departments || []).length, positions: (orgStructure.job_roles || []).length }) }}
          </p>
        </VCardText>
        <VCardText v-else>
          <JobdomainPresetTab
            :key="family"
            :jobdomain-id="id"
            :kind="family"
            :state="familyState(family)"
            :meta="meta"
            @changed="load"
            @link="fk => linkFamily(family, fk)"
          />
        </VCardText>
      </VCard>
    </template>

    <!-- ── Publication ── -->
    <template v-else-if="section === 'publication'">
      <VRow>
        <VCol
          cols="12"
          md="6"
        >
          <VCard data-test="jd-publication">
            <VCardItem>
              <VCardTitle>{{ t('jobdomainExperience.publicationTitle') }}</VCardTitle>
              <VCardSubtitle class="text-wrap">{{ t('jobdomainExperience.publicationHint') }}</VCardSubtitle>
            </VCardItem>
            <VList>
              <VListItem
                :title="t('jobdomainExperience.activeLabel')"
                :subtitle="t('jobdomainExperience.activeHint')"
              >
                <template #append>
                  <VSwitch
                    :model-value="!!profile.identity.is_active"
                    :disabled="saving"
                    hide-details
                    color="success"
                    data-test="jd-active"
                    @update:model-value="v => toggle('is_active', v)"
                  />
                </template>
              </VListItem>
              <VListItem
                :title="t('jobdomainProfile.signupAvailable')"
                :subtitle="t('jobdomainExperience.signupHint')"
              >
                <template #append>
                  <VSwitch
                    :model-value="!!profile.identity.signup_available"
                    :disabled="saving || !profile.identity.is_active"
                    hide-details
                    color="success"
                    data-test="jd-signup-available"
                    @update:model-value="v => toggle('signup_available', v)"
                  />
                </template>
              </VListItem>
            </VList>
          </VCard>
        </VCol>
        <VCol
          cols="12"
          md="6"
        >
          <VCard data-test="jd-creation-path">
            <VCardItem>
              <VCardTitle>{{ t('jobdomainExperience.creationTitle') }}</VCardTitle>
              <VCardSubtitle class="text-wrap">{{ t('jobdomainExperience.creationHint') }}</VCardSubtitle>
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
                    :color="creationTone(c.path)"
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
        class="mt-6"
        data-test="jd-advanced"
      >
        <VExpansionPanel>
          <VExpansionPanelTitle>
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
