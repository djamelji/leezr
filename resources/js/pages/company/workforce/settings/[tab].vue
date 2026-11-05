<script setup>
// ADR-639 (W-B) — centre de paramètres RH : point d'entrée unique, sections conditionnées par les
// modules actifs + permissions. Agrégateur (réutilise les endpoints existants), ne duplique aucune donnée.
definePage({
  meta: { surface: 'structure', module: 'workforce', navActiveLink: 'company-workforce-settings-tab', permission: 'workforce.view' },
})

import WorkforceRulesSection from './_WorkforceRulesSection.vue'
import ContractSettingsSection from './_ContractSettingsSection.vue'
import SignatureSettingsSection from './_SignatureSettingsSection.vue'
import { $api } from '@/utils/api'
import { useAppToast } from '@/composables/useAppToast'
import { useCan } from '@/composables/useCan'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const { toast } = useAppToast()
const { can } = useCan()

const data = ref(null)
const loading = ref(true)

// ── W-G4B : préférence de premier jour de semaine (Company) ──
const weekStart = ref(null) // null = auto (Market/locale), 1 = lundi, 0 = dimanche
const weekStartSaving = ref(false)
const weekStartOptions = computed(() => [
  { title: t('workforceSettings.weekStart.auto'), value: null },
  { title: t('workforceSettings.weekStart.monday'), value: 1 },
  { title: t('workforceSettings.weekStart.sunday'), value: 0 },
])
const loadWeekStart = async () => {
  try {
    const r = await $api('/workforce/config/week-start')
    weekStart.value = r.source === 'company' ? r.week_start_day : null
  } catch { /* ignore */ }
}
const saveWeekStart = async () => {
  weekStartSaving.value = true
  try {
    await $api('/workforce/config/week-start', { method: 'PUT', body: { week_start_day: weekStart.value } })
    toast(t('workforceSettings.weekStart.saved'), 'success')
  } catch (e) {
    toast((e.data?.message || e.response?._data?.message) || t('common.error'), 'error')
  } finally { weekStartSaving.value = false }
}

const SECTION_ICON = {
  general: 'tabler-info-circle', worktime: 'tabler-clock',
  contract: 'tabler-file-text', signature: 'tabler-signature',
}

// PRODUCT-UX-3 (P2-B) — legacy launcher tabs removed: redirect old deep-links to the
// business space where the setting now lives (no broken links, no launcher pages).
// Done SYNCHRONOUSLY in setup so it wins over VTabs auto-correcting to the first tab.
const LEGACY_TAB_REDIRECT = {
  organization: '/company/workforce/organization/departments',
  leave: '/company/workforce/leave',
  planning: '/company/workforce/time?tab=planning',
  documents: '/company/workforce/documents?tab=templates',
  rules: '/company/workforce/settings/worktime', // merged into "worktime"
}
if (LEGACY_TAB_REDIRECT[route.params.tab]) {
  router.replace(LEGACY_TAB_REDIRECT[route.params.tab])
}

const sections = computed(() => data.value?.sections || [])
const ctx = computed(() => data.value?.context || {})
const currentTab = computed({
  get: () => route.params.tab || sections.value[0]?.key || 'general',
  set: v => router.replace({ params: { tab: v } }),
})

const load = async () => {
  loading.value = true
  try {
    data.value = await $api('/workforce/settings')
    // Legacy deep-link handling: a removed launcher tab → its business space;
    // any unknown tab → first available section.
    const tab = route.params.tab
    const keys = sections.value.map(s => s.key)
    if (tab && !keys.includes(tab)) {
      if (Object.prototype.hasOwnProperty.call(LEGACY_TAB_REDIRECT, tab) && LEGACY_TAB_REDIRECT[tab]) {
        router.replace(LEGACY_TAB_REDIRECT[tab])
      } else {
        router.replace({ params: { tab: keys[0] || 'general' } })
      }
    }
  }
  finally { loading.value = false }
}

const go = p => router.push(p)

onMounted(() => { load(); loadWeekStart() })
</script>

<template>
  <div>
    <div class="mb-6">
      <h4 class="text-h4 font-weight-bold">
        {{ t('hrSettings.title') }}
      </h4>
      <p class="text-body-1 text-medium-emphasis mb-0">
        {{ t('hrSettings.subtitle') }}
      </p>
    </div>

    <VSkeletonLoader
      v-if="loading"
      type="card"
    />

    <VCard v-else-if="data">
      <VTabs
        v-model="currentTab"
        class="v-tabs-pill"
        show-arrows
      >
        <VTab
          v-for="s in sections"
          :key="s.key"
          :value="s.key"
        >
          <VIcon
            :icon="SECTION_ICON[s.key]"
            start
          />
          {{ t('hrSettings.sections.' + s.key) }}
        </VTab>
      </VTabs>

      <VCardText>
        <VWindow v-model="currentTab">
          <!-- Général -->
          <VWindowItem value="general">
            <p class="text-body-2 text-medium-emphasis mb-4">
              {{ t('hrSettings.generalIntro') }}
            </p>
            <VList lines="two">
              <VListItem
                :title="ctx.company_name"
                :subtitle="t('hrSettings.company')"
              />
              <VListItem
                :title="ctx.market_key"
                :subtitle="t('hrSettings.market')"
              />
              <VListItem
                :title="ctx.jobdomain_key"
                :subtitle="t('hrSettings.jobdomain')"
              />
              <VListItem
                :title="ctx.org_profile_label || t('hrSettings.noProfile')"
                :subtitle="t('hrSettings.profile')"
              />
            </VList>
            <!-- W-G4B : premier jour de semaine (préférence Company) -->
            <VDivider class="my-4" />
            <div class="text-subtitle-1 font-weight-medium mb-1">
              {{ t('workforceSettings.weekStart.title') }}
            </div>
            <p class="text-body-2 text-medium-emphasis mb-2">
              {{ t('workforceSettings.weekStart.hint') }}
            </p>
            <div class="d-flex gap-3 align-center flex-wrap mb-4">
              <AppSelect
                v-model="weekStart"
                :items="weekStartOptions"
                density="compact"
                style="max-inline-size: 280px;"
                :disabled="!can('workforce.manage')"
                data-test="week-start-select"
              />
              <VBtn
                color="primary"
                size="small"
                :loading="weekStartSaving"
                :disabled="!can('workforce.manage')"
                @click="saveWeekStart"
              >
                {{ t('common.save') }}
              </VBtn>
            </div>

            <VBtn
              variant="tonal"
              prepend-icon="tabler-wand"
              @click="go('/company/workforce/onboarding')"
            >
              {{ t('workforceOnboarding.launch') }}
            </VBtn>
          </VWindowItem>

          <!-- Temps de travail & pauses (éditable, avec provenance) -->
          <VWindowItem value="worktime">
            <p class="text-body-2 text-medium-emphasis mb-2">
              {{ t('hrSettings.worktimeIntro') }}
            </p>
            <h6 class="text-h6 mt-4 mb-2">
              {{ t('hrSettings.sections.worktime') }}
            </h6>
            <WorkforceRulesSection domain="work-time" />
            <h6 class="text-h6 mt-6 mb-2">
              {{ t('hrSettings.breaks') }}
            </h6>
            <WorkforceRulesSection domain="breaks" />
          </VWindowItem>

          <!-- Contrats de travail (W-CONTRACT-3B) — visible si module Contract actif -->
          <VWindowItem value="contract">
            <ContractSettingsSection :employer-identity="ctx.employer_identity || {}" />
          </VWindowItem>

          <!-- Signature électronique (W-SIGNATURE-1) — visible si module Signature actif -->
          <VWindowItem value="signature">
            <SignatureSettingsSection />
          </VWindowItem>
        </VWindow>
      </VCardText>
    </VCard>
  </div>
</template>
