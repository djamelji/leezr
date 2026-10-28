<script setup>
// ADR-639 (W-B) — centre de paramètres RH : point d'entrée unique, sections conditionnées par les
// modules actifs + permissions. Agrégateur (réutilise les endpoints existants), ne duplique aucune donnée.
definePage({
  meta: { surface: 'structure', module: 'workforce', navActiveLink: 'company-workforce-settings-tab', permission: 'workforce.view' },
})

import WorkforceRulesSection from './_WorkforceRulesSection.vue'
import { $api } from '@/utils/api'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()

const data = ref(null)
const loading = ref(true)

const SECTION_ICON = {
  general: 'tabler-info-circle', organization: 'tabler-sitemap', worktime: 'tabler-clock',
  leave: 'tabler-beach', planning: 'tabler-calendar', documents: 'tabler-file-certificate', rules: 'tabler-scale',
}

const sections = computed(() => data.value?.sections || [])
const ctx = computed(() => data.value?.context || {})
const currentTab = computed({
  get: () => route.params.tab || sections.value[0]?.key || 'general',
  set: v => router.replace({ params: { tab: v } }),
})

const load = async () => {
  loading.value = true
  try { data.value = await $api('/workforce/settings') }
  finally { loading.value = false }
}

const go = p => router.push(p)

onMounted(load)
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
            <VBtn
              variant="tonal"
              prepend-icon="tabler-wand"
              @click="go('/company/workforce/onboarding')"
            >
              {{ t('workforceOnboarding.launch') }}
            </VBtn>
          </VWindowItem>

          <!-- Organisation -->
          <VWindowItem value="organization">
            <p class="text-body-2 text-medium-emphasis mb-4">
              {{ t('hrSettings.organizationIntro') }}
            </p>
            <VBtn
              color="primary"
              prepend-icon="tabler-sitemap"
              @click="go('/company/workforce/organization/departments')"
            >
              {{ t('hrSettings.manageOrganization') }}
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

          <!-- Congés -->
          <VWindowItem value="leave">
            <p class="text-body-2 text-medium-emphasis mb-4">
              {{ t('hrSettings.leaveIntro') }}
            </p>
            <VBtn
              color="primary"
              prepend-icon="tabler-beach"
              @click="go('/company/workforce/leave')"
            >
              {{ t('hrSettings.manageLeave') }}
            </VBtn>
          </VWindowItem>

          <!-- Planning -->
          <VWindowItem value="planning">
            <p class="text-body-2 text-medium-emphasis mb-4">
              {{ t('hrSettings.planningIntro') }}
            </p>
            <VBtn
              color="primary"
              prepend-icon="tabler-calendar"
              @click="go('/company/workforce/planning')"
            >
              {{ t('hrSettings.managePlanning') }}
            </VBtn>
          </VWindowItem>

          <!-- Documents -->
          <VWindowItem value="documents">
            <p class="text-body-2 text-medium-emphasis mb-4">
              {{ t('hrSettings.documentsIntro') }}
            </p>
            <VBtn
              color="primary"
              prepend-icon="tabler-file-certificate"
              @click="go('/company/workforce/documents')"
            >
              {{ t('hrSettings.manageDocuments') }}
            </VBtn>
          </VWindowItem>

          <!-- Règles locales & conventions (provenance, lecture seule) -->
          <VWindowItem value="rules">
            <VAlert
              type="info"
              variant="tonal"
              density="compact"
              class="mb-4"
            >
              {{ t('hrSettings.rulesIntro') }}
            </VAlert>
            <h6 class="text-h6 mb-2">
              {{ t('hrSettings.sections.worktime') }}
            </h6>
            <WorkforceRulesSection
              domain="work-time"
              read-only
            />
            <h6 class="text-h6 mt-6 mb-2">
              {{ t('hrSettings.breaks') }}
            </h6>
            <WorkforceRulesSection
              domain="breaks"
              read-only
            />
          </VWindowItem>
        </VWindow>
      </VCardText>
    </VCard>
  </div>
</template>
