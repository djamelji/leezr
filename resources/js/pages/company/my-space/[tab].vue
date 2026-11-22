<script setup>
import AppPageHeader from '@/components/app/AppPageHeader.vue'
// ADR-769 (M7 LOT 3) — « Mon espace » : point d'entrée UNIQUE du self-service de la personne
// connectée, indépendant de son rôle administratif et des modules de l'entreprise. Chaque rubrique
// n'apparaît que si sa capacité existe réellement (réponse serveur `/profile/space`) ; aucun
// formulaire n'est dupliqué : documents, bulletins et signatures réutilisent les moteurs existants,
// l'activité Workforce reste sur sa page dédiée, l'identité et la sécurité sur les paramètres du compte.
import { useRoute, useRouter } from 'vue-router'
import AppSectionNavigation from '@/core/components/AppSectionNavigation.vue'
import AccountSettingsDocuments from '@/views/pages/account-settings/AccountSettingsDocuments.vue'
import MyPayslips from '@/views/pages/my-space/MyPayslips.vue'
import MySignatures from '@/views/pages/my-space/MySignatures.vue'
import { usePersonalSpaceStore } from '@/modules/company/me/personal-space.store'
import { useAuthStore } from '@/core/stores/auth'
import { $api } from '@/utils/api'

definePage({ meta: { navActiveLink: 'company-my-space-tab' } })

const { t } = useI18n()
const route = useRoute('company-my-space-tab')
const router = useRouter()
const space = usePersonalSpaceStore()
const auth = useAuthStore()

const TABS = [
  { value: 'overview', icon: 'tabler-layout-dashboard', cap: 'profile' },
  { value: 'documents', icon: 'tabler-file-text', cap: 'documents' },
  { value: 'payslips', icon: 'tabler-receipt', cap: 'payslips' },
  { value: 'signatures', icon: 'tabler-writing-sign', cap: 'signatures' },
]
const sections = computed(() => TABS.filter(x => space.can(x.cap)).map(x => ({ value: x.value, icon: x.icon, label: t(`mySpace.tabs.${x.value}`), short: t(`mySpace.tabsShort.${x.value}`) }))) // ADR-862 (A2) : whole phone labels
const currentTab = computed({
  get: () => route.params.tab,
  set: tab => router.push({ name: 'company-my-space-tab', params: { tab } }),
})

// ── Mes documents : même composant et mêmes endpoints que l'ancien onglet des paramètres du compte ──
const documents = ref([])
const documentsLoading = ref(false)
const loadDocuments = async () => {
  if (!space.can('documents')) return
  documentsLoading.value = true
  try { documents.value = (await $api('/profile/documents')).documents || [] }
  catch { documents.value = [] }
  finally { documentsLoading.value = false }
}

// Une rubrique n'est RENDUE que si sa capacité existe : en accès direct par URL à une rubrique
// indisponible, rien ne se monte (donc aucun appel API) avant le retour à l'aperçu.
const activeTab = computed(() => (sections.value.some(x => x.value === route.params.tab) ? route.params.tab : null))

const ensureTab = () => {
  if (!sections.value.some(s => s.value === route.params.tab))
    router.replace({ name: 'company-my-space-tab', params: { tab: 'overview' } })
}

onMounted(async () => {
  await space.load()
  ensureTab()
  if (route.params.tab === 'documents') loadDocuments()
})
watch(() => route.params.tab, tab => {
  if (!space.ready) return
  ensureTab()
  if (tab === 'documents' && !documents.value.length) loadDocuments()
})

// ── Rubriques de l'aperçu : uniquement les capacités réelles ──
const firstName = computed(() => auth.user?.first_name || '')
const toTab = tab => ({ name: 'company-my-space-tab', params: { tab } })
const entries = computed(() => {
  const c = space.counts
  const all = [
    { key: 'documents', cap: 'documents', icon: 'tabler-file-text', color: 'warning', to: toTab('documents'), badge: c.document_requests },
    { key: 'payslips', cap: 'payslips', icon: 'tabler-receipt', color: 'primary', to: toTab('payslips'), badge: 0, info: c.payslips },
    { key: 'signatures', cap: 'signatures', icon: 'tabler-writing-sign', color: 'info', to: toTab('signatures'), badge: c.signatures_to_sign },
    { key: 'activity', cap: 'employment', icon: 'tabler-calendar-time', color: 'success', to: { name: 'company-workforce-me' } },
    { key: 'profile', cap: 'profile', icon: 'tabler-user', color: 'secondary', to: { name: 'account-settings-tab', params: { tab: 'account' } } },
    { key: 'security', cap: 'profile', icon: 'tabler-lock', color: 'secondary', to: { name: 'account-settings-tab', params: { tab: 'security' } } },
  ]

  return all.filter(e => space.can(e.cap))
})
const activityHint = computed(() => {
  const parts = [t('mySpace.entries.activityParts.time')]
  if (space.can('planning')) parts.unshift(t('mySpace.entries.activityParts.planning'))
  if (space.can('leave')) parts.push(t('mySpace.entries.activityParts.leave'))
  if (space.can('hr_documents')) parts.push(t('mySpace.entries.activityParts.documents'))

  return parts.join(' · ')
})
</script>

<template>
  <div data-test="my-space">
    <AppPageHeader
      :eyebrow="t('l7.family.me')"
      :title="t('mySpace.title')"
      :description="t('mySpace.subtitle')"
    />

    <VSkeletonLoader
      v-if="space.loading && !space.ready"
      type="article, list-item-two-line@3"
    />
    <VAlert
      v-else-if="space.failed"
      type="error"
      variant="tonal"
    >
      {{ t('mySpace.loadError') }}
      <template #append>
        <VBtn
          size="small"
          variant="text"
          @click="space.load(true)"
        >
          {{ t('common.retry') }}
        </VBtn>
      </template>
    </VAlert>

    <template v-else-if="space.ready">
      <AppSectionNavigation
        v-if="sections.length > 1"
        v-model="currentTab"
        :sections="sections"
        class="mb-5"
      />

      <!-- ═══ Aperçu : rubriques personnelles réellement disponibles ═══ -->
      <VCard v-if="activeTab === 'overview'">
        <VList
          lines="two"
          class="py-2"
          role="navigation"
          :aria-label="t('mySpace.title')"
        >
          <VListItem
            v-for="e in entries"
            :key="e.key"
            :to="e.to"
            :data-test="`my-space-entry-${e.key}`"
          >
            <template #prepend>
              <VAvatar
                :color="e.color"
                variant="tonal"
                rounded
              >
                <VIcon :icon="e.icon" />
              </VAvatar>
            </template>
            <VListItemTitle class="font-weight-medium">
              {{ t(`mySpace.entries.${e.key}.title`) }}
            </VListItemTitle>
            <VListItemSubtitle>
              {{ e.key === 'activity' ? activityHint : t(`mySpace.entries.${e.key}.hint`) }}
            </VListItemSubtitle>
            <template #append>
              <VChip
                v-if="e.badge"
                color="warning"
                size="small"
                label
                class="me-2"
                :data-test="`my-space-badge-${e.key}`"
              >
                {{ t(`mySpace.entries.${e.key}.badge`, { n: e.badge }, e.badge) }}
              </VChip>
              <span
                v-else-if="e.info"
                class="text-body-2 text-medium-emphasis me-2"
              >{{ e.info }}</span>
              <VIcon
                icon="tabler-chevron-right"
                size="18"
                class="text-disabled"
              />
            </template>
          </VListItem>
        </VList>
        <VCardText
          v-if="!space.hasEmployee && firstName"
          class="text-body-2 text-medium-emphasis pt-0"
          data-test="my-space-no-employee"
        >
          {{ t('mySpace.noEmployee') }}
        </VCardText>
      </VCard>

      <!-- ═══ Mes documents (DMS existant) ═══ -->
      <template v-else-if="activeTab === 'documents'">
        <VSkeletonLoader
          v-if="documentsLoading && !documents.length"
          type="table-row@4"
        />
        <VCard v-else-if="!documents.length">
          <VCardText class="text-center text-medium-emphasis py-10">
            <VIcon
              icon="tabler-file-off"
              size="44"
              class="text-disabled mb-3"
            />
            <div class="text-body-1 mb-1">
              {{ t('mySpace.documents.emptyTitle') }}
            </div>
            <div class="text-body-2">
              {{ t('mySpace.documents.emptyHint') }}
            </div>
          </VCardText>
        </VCard>
        <AccountSettingsDocuments
          v-else
          :documents="documents"
          @refresh="loadDocuments(); space.load(true)"
        />
      </template>

      <VCard v-else-if="activeTab === 'payslips'">
        <VCardText><MyPayslips /></VCardText>
      </VCard>

      <VCard v-else-if="activeTab === 'signatures'">
        <VCardText><MySignatures /></VCardText>
      </VCard>
    </template>
  </div>
</template>
