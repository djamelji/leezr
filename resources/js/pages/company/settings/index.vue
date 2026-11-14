<script setup>
// ADR-781 (Company Experience 2.0 / UX5) — RÉGLAGES : un seul point d'entrée.
// Les entrées viennent du SERVEUR (espace `settings` de NavBuilder) : chaque module contribue les
// siennes par son manifeste, déjà filtrées par module actif, plan, surface et permissions. Cette page
// ne connaît aucun module : elle range ces entrées par rubrique (`settings.section`) et par niveau
// (`settings.level` : essentiel, courant, avancé). Une rubrique sans entrée n'existe pas.
import { useNavStore } from '@/core/stores/nav'
import { useCompanySettingsStore } from '@/modules/company/settings/settings.store'
import { useCompanyPermissionContext } from '@/composables/useCompanyPermissionContext'
import { $api } from '@/utils/api'

definePage({ meta: { surface: 'structure' } })

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
      status.value.company_profile = missing.length ? { color: 'warning', text: t('settingsHub.status.missingFields', { n: missing.length }) } : { color: 'success', text: t('settingsHub.status.complete') }
    } catch { /* pas d'état plutôt qu'un état faux */ }
  }
  if (probes.includes('signature_provider') && can('workforce_signature.view')) {
    try {
      const o = await $api('/workforce/signature/options')
      status.value.signature_provider = (!o?.provider || o?.provider_dev_only)
        ? { color: 'warning', text: t('settingsHub.status.providerTest') }
        : { color: 'success', text: t('settingsHub.status.providerReady') }
    } catch { /* idem */ }
  }
})
const statusOf = e => (e.settings?.status ? status.value[e.settings.status] : null)
</script>

<template>
  <div class="settings-hub">
    <div class="d-flex align-center flex-wrap gap-3 mb-2">
      <h4 class="text-h4 flex-grow-1">
        {{ t('settingsHub.title') }}
      </h4>
      <AppTextField
        v-model="search"
        :placeholder="t('settingsHub.search')"
        prepend-inner-icon="tabler-search"
        clearable
        density="compact"
        style="max-inline-size: 320px; min-inline-size: 220px;"
        data-test="settings-search"
      />
    </div>
    <p class="text-body-2 text-medium-emphasis mb-6">
      {{ t('settingsHub.subtitle') }}
    </p>

    <div
      v-if="!entries.length"
      class="text-center text-medium-emphasis py-12"
      data-test="settings-empty"
    >
      {{ t('settingsHub.empty') }}
    </div>
    <div
      v-else-if="!sections.length && !advanced.length"
      class="text-center text-medium-emphasis py-12"
    >
      {{ t('settingsHub.noMatch') }}
    </div>

    <section
      v-for="s in sections"
      :key="s.key"
      class="mb-6"
      :data-test="`settings-section-${s.key}`"
    >
      <div class="d-flex align-center gap-2 mb-3">
        <VIcon
          :icon="SECTION_ICONS[s.key]"
          size="20"
          class="text-medium-emphasis"
        />
        <h5 class="text-h6">
          {{ t(`settingsHub.sections.${s.key}`) }}
        </h5>
      </div>
      <VRow class="card-grid card-grid-sm">
        <VCol
          v-for="e in s.items"
          :key="e.key"
          cols="12"
          sm="6"
          lg="4"
        >
          <VCard
            :to="e.to"
            class="settings-entry"
            :data-test="`settings-entry-${e.key}`"
          >
            <VCardText class="d-flex gap-3 align-start">
              <VAvatar
                color="primary"
                variant="tonal"
                rounded
                size="40"
              >
                <VIcon
                  :icon="e.icon"
                  size="22"
                />
              </VAvatar>
              <div class="flex-grow-1 min-w-0">
                <div class="d-flex align-center flex-wrap gap-2">
                  <span class="text-body-1 font-weight-medium">{{ e.label }}</span>
                  <VChip
                    v-if="e.level === 'essential'"
                    size="x-small"
                    color="primary"
                    variant="tonal"
                    label
                  >
                    {{ t('settingsHub.levels.essential') }}
                  </VChip>
                </div>
                <div
                  v-if="e.hint"
                  class="text-body-2 text-medium-emphasis"
                >
                  {{ e.hint }}
                </div>
                <VChip
                  v-if="statusOf(e)"
                  size="small"
                  :color="statusOf(e).color"
                  variant="tonal"
                  class="mt-2"
                  :data-test="`settings-status-${e.key}`"
                >
                  {{ statusOf(e).text }}
                </VChip>
              </div>
              <VIcon
                icon="tabler-chevron-right"
                class="text-disabled"
              />
            </VCardText>
          </VCard>
        </VCol>
      </VRow>
    </section>

    <!-- Administration avancée : repliée, jamais au premier niveau. -->
    <VCard
      v-if="advanced.length"
      variant="outlined"
      data-test="settings-advanced"
    >
      <VCardText class="py-3">
        <button
          type="button"
          class="d-flex align-center gap-2 w-100 text-start"
          :aria-expanded="advancedOpen"
          data-test="settings-advanced-toggle"
          @click="advancedOpen = !advancedOpen"
        >
          <VIcon
            icon="tabler-adjustments"
            size="20"
          />
          <span class="text-body-1 font-weight-medium flex-grow-1">{{ t('settingsHub.advanced') }}</span>
          <span class="text-body-2 text-medium-emphasis d-none d-sm-inline">{{ t('settingsHub.advancedHint') }}</span>
          <VIcon :icon="advancedOpen ? 'tabler-chevron-up' : 'tabler-chevron-down'" />
        </button>
        <VExpandTransition>
          <VList
            v-if="advancedOpen"
            class="mt-2"
            density="comfortable"
          >
            <VListItem
              v-for="e in advanced"
              :key="e.key"
              :to="e.to"
              :prepend-icon="e.icon"
              :title="e.label"
              :subtitle="e.hint"
              :data-test="`settings-entry-${e.key}`"
            />
          </VList>
        </VExpandTransition>
      </VCardText>
    </VCard>
  </div>
</template>

<style scoped>
.settings-entry { block-size: 100%; }
</style>
