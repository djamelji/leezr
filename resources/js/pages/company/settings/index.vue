<script setup>
import AppPageHeader from '@/components/app/AppPageHeader.vue'
// ADR-781 (Company Experience 2.0 / UX5) — RÉGLAGES : un seul point d'entrée.
// Les entrées viennent du SERVEUR (espace `settings` de NavBuilder) : chaque module contribue les
// siennes par son manifeste, déjà filtrées par module actif, plan, surface et permissions. Cette page
// ne connaît aucun module : elle range ces entrées par rubrique (`settings.section`) et par niveau
// (`settings.level` : essentiel, courant, avancé). Une rubrique sans entrée n'existe pas.
import { useNavStore } from '@/core/stores/nav'
import { useCompanySettingsStore } from '@/modules/company/settings/settings.store'
import { useCompanyPermissionContext } from '@/composables/useCompanyPermissionContext'
import { $api } from '@/utils/api'
import { useDisplay } from 'vuetify'

// ADR-786 : le hub ne présente QUE les entrées déjà filtrées par le serveur (module, plan, rôle,
// permissions) ; chaque cible garde sa propre garde. Exiger ici un rôle administratif contredisait le
// menu (ex. « Consultation » voyait « Réglages › Organisation » puis tombait sur un 403).
definePage({ meta: {} })

const { t, te } = useI18n()
const nav = useNavStore()
const settingsStore = useCompanySettingsStore()
const { can } = useCompanyPermissionContext()

const SECTIONS = ['company', 'organization', 'access', 'work', 'documents', 'billing', 'automation']
const SECTION_ICONS = {
  company: 'tabler-building', organization: 'tabler-map-pin', access: 'tabler-shield-lock', work: 'tabler-briefcase',
  documents: 'tabler-files', billing: 'tabler-receipt', automation: 'tabler-plug-connected',
}

const entries = computed(() => {
  const group = nav.companyGroups.find(g => g.key === 'settings')
  const hub = group?.items?.find(i => i.hub)

  return (hub?.children || []).map(e => ({
    ...e,
    section: e.settings?.section || 'automation',
    level: e.settings?.level || 'regular',
    label: t(`nav.company.${e.key}`),
    hint: te(`settingsHub.hints.${e.key}`) ? t(`settingsHub.hints.${e.key}`) : '',
    to: { ...e.to, query: { ...(e.to?.query || {}), from: 'settings' } },
  }))
})

const search = ref('')
const matches = e => {
  const q = search.value.trim().toLowerCase()
  if (!q) return true

  return [e.label, e.hint, t(`settingsHub.sections.${e.section}`)].join(' ').toLowerCase().includes(q)
}

const sections = computed(() => SECTIONS
  .map(key => ({ key, items: entries.value.filter(e => e.section === key && e.level !== 'advanced' && matches(e)) }))
  .filter(s => s.items.length))
const advanced = computed(() => entries.value.filter(e => e.level === 'advanced' && matches(e)))
const advancedOpen = ref(false)
watch(search, v => { if (v.trim() && advanced.value.length) advancedOpen.value = true })

// ── États « à compléter » : seulement quand une donnée réelle le prouve, et lue avec les droits de
//    l'utilisateur (jamais une requête qu'il ne pourrait pas faire). ─────────────────────────────
const status = ref({})
onMounted(async () => {
  const probes = entries.value.map(e => e.settings?.status).filter(Boolean)
  if (probes.includes('company_profile')) {
    try {
      if (!settingsStore.company) await settingsStore.fetchCompany()
      const values = Object.fromEntries((settingsStore.company?.dynamic_fields || []).map(f => [f.code, f.value]))
      const missing = (settingsStore.company?.jobdomain_mandatory_fields || []).filter(f => values[f.code] === null || values[f.code] === undefined || values[f.code] === '')
      status.value.company_profile = missing.length ? { tone: 'warning', text: t('settingsHub.status.missingFields', { n: missing.length }) } : { tone: 'success', text: t('settingsHub.status.complete') }
    } catch { /* pas d'état plutôt qu'un état faux */ }
  }
  if (probes.includes('signature_provider') && can('workforce_signature.view')) {
    try {
      const o = await $api('/workforce/signature/options')
      status.value.signature_provider = (!o?.provider || o?.provider_dev_only)
        ? { tone: 'warning', text: t('settingsHub.status.providerTest') }
        : { tone: 'success', text: t('settingsHub.status.providerReady') }
    } catch { /* idem */ }
  }
})
const statusOf = e => (e.settings?.status ? status.value[e.settings.status] : null)

// ── ADR-820 — composition Vuexy : rubriques en VList (desktop) / VTabs défilants (mobile), une VCard par
//    rubrique, entrées en VListItem. Rubrique active suivie au défilement. Aucune donnée ni requête en plus. ──
const { mdAndUp } = useDisplay()
const navItems = computed(() => [
  ...sections.value.map(s => ({ key: s.key, label: t(`settingsHub.sections.${s.key}`), icon: SECTION_ICONS[s.key], count: s.items.length })),
  ...(advanced.value.length ? [{ key: 'advanced', label: t('settingsHub.advancedShort'), icon: 'tabler-adjustments', count: advanced.value.length }] : []),
])
const activeKey = ref(null)
const goTo = key => {
  if (key === 'advanced') advancedOpen.value = true
  activeKey.value = key
  nextTick(() => document.getElementById(`settings-${key}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' }))
}
let observer = null
const observe = () => {
  observer?.disconnect()
  if (typeof IntersectionObserver === 'undefined') return
  observer = new IntersectionObserver(list => {
    const visible = list.filter(e => e.isIntersecting).sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top)
    if (visible[0]) activeKey.value = visible[0].target.id.replace('settings-', '')
  }, { rootMargin: '-96px 0px -55% 0px' })
  document.querySelectorAll('[data-settings-section]').forEach(el => observer.observe(el))
}
watch(navItems, () => nextTick(observe), { flush: 'post' })
onMounted(() => nextTick(observe))
onBeforeUnmount(() => observer?.disconnect())
const advancedPanel = computed({ get: () => (advancedOpen.value ? 0 : null), set: v => { advancedOpen.value = v === 0 } })
</script>

<template>
  <div>
    <AppPageHeader
      :eyebrow="t('l7.family.settings')"
      :title="t('settingsHub.title')"
      :description="t('settingsHub.subtitle')"
    >
      <template #actions>
        <AppTextField
          v-model="search"
          :placeholder="t('settingsHub.search')"
          prepend-inner-icon="tabler-search"
          clearable
          :style="{ inlineSize: mdAndUp ? '300px' : '100%' }"
          data-test="settings-search"
        />
      </template>
    </AppPageHeader>

    <VCard
      v-if="!entries.length"
      data-test="settings-empty"
    >
      <VCardText class="text-center py-12 text-medium-emphasis">
        <VIcon
          icon="tabler-settings-off"
          size="28"
          class="mb-2"
        />
        <div>{{ t('settingsHub.empty') }}</div>
      </VCardText>
    </VCard>

    <VRow v-else>
      <!-- Rubriques : liste à gauche (desktop), onglets défilants (mobile) -->
      <VCol
        v-if="navItems.length > 1 && mdAndUp"
        cols="12"
        md="3"
      >
        <VCard
          class="position-sticky"
          style="inset-block-start: 88px;"
        >
          <VList
            nav
            density="compact"
            :aria-label="t('settingsHub.title')"
          >
            <VListItem
              v-for="n in navItems"
              :key="n.key"
              :prepend-icon="n.icon"
              :title="n.label"
              :active="activeKey === n.key"
              color="primary"
              :data-test="`settings-nav-${n.key}`"
              @click="goTo(n.key)"
            />
          </VList>
        </VCard>
      </VCol>
      <VCol
        cols="12"
        :md="navItems.length > 1 ? 9 : 12"
      >
        <VTabs
          v-if="navItems.length > 1 && !mdAndUp"
          :model-value="activeKey"
          show-arrows
          class="border-b mb-4"
        >
          <VTab
            v-for="n in navItems"
            :key="n.key"
            :value="n.key"
            :prepend-icon="n.icon"
            :data-test="`settings-nav-${n.key}`"
            @click="goTo(n.key)"
          >
            {{ n.label }}
          </VTab>
        </VTabs>

        <VCard v-if="!sections.length && !advanced.length">
          <VCardText class="text-center py-12 text-medium-emphasis">
            {{ t('settingsHub.noMatch') }}
          </VCardText>
        </VCard>

        <VCard
          v-for="s in sections"
          :id="`settings-${s.key}`"
          :key="s.key"
          data-settings-section
          class="mb-6"
          :data-test="`settings-section-${s.key}`"
        >
          <VCardItem>
            <template #prepend>
              <VAvatar
                :icon="SECTION_ICONS[s.key]"
                color="primary"
                variant="tonal"
                rounded
              />
            </template>
            <VCardTitle>{{ t(`settingsHub.sections.${s.key}`) }}</VCardTitle>
            <VCardSubtitle class="text-wrap">
              {{ t(`settingsHub.sectionHints.${s.key}`) }}
            </VCardSubtitle>
          </VCardItem>
          <VDivider />
          <VList class="py-0">
            <template
              v-for="(e, i) in s.items"
              :key="e.key"
            >
              <VDivider v-if="i" />
              <VListItem
                :to="e.to"
                :prepend-icon="e.icon"
                lines="two"
                class="py-3"
                :data-test="`settings-entry-${e.key}`"
              >
                <VListItemTitle class="font-weight-medium">
                  {{ e.label }}
                  <VChip
                    v-if="e.level === 'essential'"
                    size="x-small"
                    label
                    variant="tonal"
                    color="primary"
                    class="ms-2"
                  >
                    {{ t('settingsHub.levels.essential') }}
                  </VChip>
                </VListItemTitle>
                <VListItemSubtitle class="text-wrap">
                  {{ e.hint }}
                </VListItemSubtitle>
                <template #append>
                  <VChip
                    v-if="statusOf(e)"
                    size="small"
                    label
                    variant="tonal"
                    :color="statusOf(e).tone"
                    class="me-2"
                    :data-test="`settings-status-${e.key}`"
                  >
                    {{ statusOf(e).text }}
                  </VChip>
                  <VIcon
                    icon="tabler-chevron-right"
                    size="18"
                    class="text-disabled"
                  />
                </template>
              </VListItem>
            </template>
          </VList>
        </VCard>

        <!-- Administration avancée : repliée -->
        <VExpansionPanels
          v-if="advanced.length"
          id="settings-advanced"
          v-model="advancedPanel"
          data-settings-section
          data-test="settings-advanced"
        >
          <VExpansionPanel>
            <VExpansionPanelTitle data-test="settings-advanced-toggle">
              <div>
                <div class="text-body-1 font-weight-medium">
                  {{ t('settingsHub.advanced') }}
                </div>
                <div class="text-body-2 text-medium-emphasis">
                  {{ t('settingsHub.advancedHint') }}
                </div>
              </div>
            </VExpansionPanelTitle>
            <VExpansionPanelText class="px-0">
              <VList class="py-0">
                <VListItem
                  v-for="e in advanced"
                  :key="e.key"
                  :to="e.to"
                  :prepend-icon="e.icon"
                  :title="e.label"
                  :subtitle="e.hint"
                  append-icon="tabler-chevron-right"
                  :data-test="`settings-entry-${e.key}`"
                />
              </VList>
            </VExpansionPanelText>
          </VExpansionPanel>
        </VExpansionPanels>
      </VCol>
    </VRow>
  </div>
</template>
