<script setup>
// ADR-797 → ADR-799 — JobDomain = configuration du MÉTIER et de ses recommandations, en six onglets :
// Général (identité, marchés, cadre conventionnel recommandé ; section avancée repliée) · Modules · Rôles ·
// Organisation · Champs & documents · Modèles de planning. Les presets s'éditent dans leur onglet (versions,
// brouillons, publication gérés en interne). Le JobDomain ne fixe JAMAIS l'organisation du temps de travail
// d'une société : elle la définit dans son propre assistant (Workforce).
import { $platformApi } from '@/utils/platformApi'
import { useAppToast } from '@/composables/useAppToast'
import JobdomainPresetTab from './_JobdomainPresetTab.vue'
import JobdomainAdvanced from './_JobdomainAdvanced.vue'

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
const router = useRouter()
const { toast } = useAppToast()

const TABS = ['general', 'modules', 'roles', 'organization', 'requirements', 'planning']
const TERMS = ['employee', 'position', 'team', 'site']
const tab = computed({
  get: () => (TABS.includes(route.query.tab) ? route.query.tab : 'general'),
  set: v => router.replace({ query: { ...route.query, tab: v } }),
})

const profile = ref(null)
const meta = ref(null)
const orgFamilies = ref([])
const loading = ref(true)
const saving = ref(false)
const identity = ref({})
const modules = ref([])
const refs = ref([]) // [{ market_key, convention_collective_id }]
const orgFamily = ref(null)
const id = computed(() => Number(route.params.id))

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
    modules.value = p.modules.map(x => x.key)
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
const saveGeneral = () => run(() => $platformApi(`/jobdomains/${id.value}`, { method: 'PUT', body: {
  label: identity.value.label, description: identity.value.description || null, sector: identity.value.sector || null,
  terminology: Object.fromEntries(Object.entries(identity.value.terminology).filter(([, v]) => v)),
  convention_refs: refs.value.filter(r => r.market_key && r.convention_collective_id),
} }), t('jobdomainProfile.saved'))
// Logistics Only — proposé aux NOUVELLES inscriptions (les sociétés existantes ne sont jamais affectées).
const toggleSignup = v => run(() => $platformApi(`/jobdomains/${id.value}`, { method: 'PUT', body: { signup_available: !!v } }), t('jobdomainProfile.saved'))
const saveModules = () => run(() => $platformApi(`/jobdomains/${id.value}`, { method: 'PUT', body: { default_modules: modules.value } }),
  res => (res.auto_added?.length ? t('jobdomainProfile.modulesSavedDeps', { list: res.auto_added.join(', ') }) : t('jobdomainProfile.saved')))
// ADR-802 — une seule façon d'associer une famille au domaine (toutes familles) : remplace la recommandation
// générale (sans marché) de cette famille, garde les autres liens tels quels.
const linkFamily = (kind, familyKey) => run(() => {
  const links = profile.value.recommendations.filter(r => !(r.kind === kind && !r.market_key))
    .map(r => ({ kind: r.kind, family_key: r.family_key, market_key: r.market_key, pinned_version: r.pinned_version }))
  if (familyKey) links.push({ kind, family_key: familyKey })

  return $platformApi(`/jobdomains/${profile.value.identity.key}/preset-recommendations`, { method: 'PUT', body: { links } })
}, t('jobdomainProfile.saved'))
const saveOrganization = () => linkFamily('organization', orgFamily.value)

const conventionsFor = market => (profile.value?.conventions || []).filter(c => c.market_key === market)
  .map(c => ({ value: c.id, title: `${c.name} (IDCC ${c.idcc})${c.verified ? '' : ` — ${t('jobdomainTabs.unverified')}`}` }))
const activeMarkets = computed(() => (profile.value?.markets || []).filter(m => m.is_active))
const addRef = market => refs.value.push({ market_key: market, convention_collective_id: null })
const moduleItems = computed(() => (profile.value?.available_modules || []).map(m => ({ value: m.key, title: m.name })))
const orgStructure = computed(() => profile.value?.workspace.organization.published?.content?.structure || null)
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
    <div class="d-flex flex-wrap align-center gap-3 mb-4">
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
          {{ t('jobdomainProfile.companies', { n: profile.compatibility.companies }) }}
        </div>
      </div>
      <div class="d-flex align-center gap-4 flex-wrap">
        <VSwitch
          :model-value="!!profile.identity.signup_available"
          :label="t('jobdomainProfile.signupAvailable')"
          :disabled="saving || !profile.identity.is_active"
          hide-details
          density="compact"
          color="success"
          data-test="jd-signup-available"
          @update:model-value="toggleSignup"
        />
        <AppStatusText
          :tone="profile.identity.is_active ? 'success' : 'info'"
          :text="profile.identity.is_active ? t('jobdomainProfile.active') : t('jobdomainProfile.inactive')"
        />
      </div>
    </div>

    <VTabs
      v-model="tab"
      show-arrows
      class="mb-6"
      data-test="jd-tabs"
    >
      <VTab
        v-for="k in TABS"
        :key="k"
        :value="k"
        :data-test="`jd-tabbtn-${k}`"
      >
        {{ t(`jobdomainTabs.tab.${k}`) }}
      </VTab>
    </VTabs>

    <VWindow
      v-model="tab"
      :touch="false"
      class="disable-tab-transition"
    >
      <!-- 1. Général -->
      <VWindowItem value="general">
        <VCard data-test="jd-identity">
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

            <div class="text-overline mt-6">
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
              <div class="text-body-1 mb-1">
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
                @click="addRef(m.key)"
              >
                {{ conventionsFor(m.key).length ? t('jobdomainTabs.addConvention') : t('jobdomainTabs.noConventionMarket') }}
              </VBtn>
            </div>
            <VAlert
              type="info"
              variant="tonal"
              density="compact"
            >
              {{ t('jobdomainTabs.notWorkPolicy') }}
            </VAlert>
          </VCardText>
          <VCardActions class="justify-end">
            <VBtn
              color="primary"
              :loading="saving"
              data-test="jd-save-general"
              @click="saveGeneral"
            >
              {{ t('jobdomainProfile.save') }}
            </VBtn>
          </VCardActions>
        </VCard>

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
      </VWindowItem>

      <!-- 2. Modules -->
      <VWindowItem value="modules">
        <VCard>
          <VCardText>
            <p class="text-body-2 text-medium-emphasis">
              {{ t('jobdomainProfile.modulesHint') }}
            </p>
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
                <div
                  v-if="m.requires.length"
                  class="text-body-2 text-medium-emphasis"
                >
                  {{ t('jobdomainProfile.requires', { list: m.requires.join(', ') }) }}
                </div>
              </div>
              <AppStatusText
                :tone="{ core: 'success', included: 'success', paid_optional: 'info', incompatible: 'error', unavailable: 'warning' }[m.class] || 'info'"
                :text="t(`jobdomainProfile.moduleClass.${m.class}`)"
              />
            </div>
          </VCardText>
          <VCardActions class="justify-end">
            <VBtn
              color="primary"
              :loading="saving"
              @click="saveModules"
            >
              {{ t('jobdomainProfile.save') }}
            </VBtn>
          </VCardActions>
        </VCard>
      </VWindowItem>

      <!-- 3. Rôles -->
      <VWindowItem value="roles">
        <VCard>
          <VCardText>
            <p class="text-body-2 text-medium-emphasis">
              {{ t('jobdomainTabs.rolesHint') }}
            </p>
            <JobdomainPresetTab
              :jobdomain-id="id"
              kind="rbac"
              :state="profile.workspace.rbac"
              :meta="meta"
              @changed="load"
              @link="fk => linkFamily('rbac', fk)"
            />
          </VCardText>
        </VCard>
      </VWindowItem>

      <!-- 4. Organisation -->
      <VWindowItem value="organization">
        <VCard data-test="jd-tab-organization">
          <VCardText>
            <p class="text-body-2 text-medium-emphasis">
              {{ t('jobdomainTabs.organizationHint') }}
            </p>
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
            <template v-if="orgStructure">
              <div class="text-overline">
                {{ t('jobdomainTabs.departments', { n: (orgStructure.departments || []).length }) }}
              </div>
              <div class="text-body-2 mb-3">
                {{ (orgStructure.departments || []).map(d => d.name || d.label || d.key).join(' · ') || '—' }}
              </div>
              <div class="text-overline">
                {{ t('jobdomainTabs.positions', { n: (orgStructure.job_roles || []).length }) }}
              </div>
              <div class="text-body-2">
                {{ (orgStructure.job_roles || []).map(j => j.title || j.key).join(' · ') || '—' }}
              </div>
            </template>
          </VCardText>
        </VCard>
      </VWindowItem>

      <!-- 5. Champs & documents -->
      <VWindowItem value="requirements">
        <VCard>
          <VCardText>
            <p class="text-body-2 text-medium-emphasis">
              {{ t('jobdomainTabs.requirementsHint') }}
            </p>
            <JobdomainPresetTab
              :jobdomain-id="id"
              kind="requirements"
              :state="profile.workspace.requirements"
              :meta="meta"
              @changed="load"
              @link="fk => linkFamily('requirements', fk)"
            />
          </VCardText>
        </VCard>
      </VWindowItem>

      <!-- 6. Modèles de planning -->
      <VWindowItem value="planning">
        <VCard>
          <VCardText>
            <p class="text-body-2 text-medium-emphasis">
              {{ t('jobdomainTabs.planningHint') }}
            </p>
            <JobdomainPresetTab
              :jobdomain-id="id"
              kind="planning"
              :state="profile.workspace.planning"
              :meta="meta"
              @changed="load"
              @link="fk => linkFamily('planning', fk)"
            />
          </VCardText>
        </VCard>
      </VWindowItem>
    </VWindow>
  </div>
</template>

<style scoped>
.jd { max-inline-size: 1100px; }
.jd-row { display: flex; align-items: center; gap: 12px; padding-block: 10px; flex-wrap: wrap; }
.jd-row + .jd-row { border-block-start: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }
</style>
