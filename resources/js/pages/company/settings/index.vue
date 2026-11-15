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

// ── ADR-782 (UX6) — composition : rubriques à gauche (desktop) ou en barre défilante (mobile),
//    rubrique active suivie au défilement. Aucune donnée en plus, aucune requête en plus. ───────────
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
  document.querySelectorAll('.settings-content > section[id^="settings-"]').forEach(el => observer.observe(el))
}
watch(navItems, () => nextTick(observe), { flush: 'post' })
onMounted(() => nextTick(observe))
onBeforeUnmount(() => observer?.disconnect())
</script>

<template>
  <div class="settings-page">
    <AppPageHeader
      :title="t('settingsHub.title')"
      :description="t('settingsHub.subtitle')"
    >
      <template #actions>
        <AppTextField
          v-model="search"
          :placeholder="t('settingsHub.search')"
          prepend-inner-icon="tabler-search"
          clearable
          density="compact"
          class="settings-search"
          data-test="settings-search"
        />
      </template>
    </AppPageHeader>

    <div
      v-if="!entries.length"
      class="settings-empty"
      data-test="settings-empty"
    >
      <VIcon
        icon="tabler-settings-off"
        size="28"
        class="mb-2"
      />
      <div>{{ t('settingsHub.empty') }}</div>
    </div>

    <div
      v-else
      class="settings-layout"
      :class="{ 'settings-layout--single': navItems.length <= 1 }"
    >
      <!-- Rubriques : colonne à gauche (desktop), barre défilante (mobile) -->
      <nav
        v-if="navItems.length > 1"
        class="settings-nav"
        :class="{ 'settings-nav--bar': !mdAndUp }"
        :aria-label="t('settingsHub.title')"
      >
        <button
          v-for="n in navItems"
          :key="n.key"
          type="button"
          class="settings-nav__item"
          :class="{ 'settings-nav__item--active': activeKey === n.key }"
          :data-test="`settings-nav-${n.key}`"
          @click="goTo(n.key)"
        >
          <VIcon
            :icon="n.icon"
            size="18"
          />
          <span class="settings-nav__label">{{ n.label }}</span>
        </button>
      </nav>

      <div class="settings-content">
        <div
          v-if="!sections.length && !advanced.length"
          class="settings-empty"
        >
          {{ t('settingsHub.noMatch') }}
        </div>

        <AppSettingsGroup
          v-for="s in sections"
          :id="`settings-${s.key}`"
          :key="s.key"
          :title="t(`settingsHub.sections.${s.key}`)"
          :description="t(`settingsHub.sectionHints.${s.key}`)"
          :data-test="`settings-section-${s.key}`"
        >
          <AppSettingRow
            v-for="e in s.items"
            :key="e.key"
            :to="e.to"
            :icon="e.icon"
            :title="e.label"
            :description="e.hint"
            :hint="e.level === 'essential' ? t('settingsHub.levels.essential') : ''"
            :status="statusOf(e)"
            :status-test="`settings-status-${e.key}`"
            :data-test="`settings-entry-${e.key}`"
          />
        </AppSettingsGroup>

        <!-- Administration avancée : discrète, repliée -->
        <AppSettingsGroup
          v-if="advanced.length"
          id="settings-advanced"
          :title="t('settingsHub.advanced')"
          :description="t('settingsHub.advancedHint')"
          quiet
          data-test="settings-advanced"
        >
          <button
            v-if="!advancedOpen"
            type="button"
            class="settings-advanced-toggle"
            data-test="settings-advanced-toggle"
            :aria-expanded="advancedOpen"
            @click="advancedOpen = true"
          >
            {{ t('settingsHub.showAdvanced', { n: advanced.length }) }}
            <VIcon
              icon="tabler-chevron-down"
              size="16"
            />
          </button>
          <template v-else>
            <AppSettingRow
              v-for="e in advanced"
              :key="e.key"
              :to="e.to"
              :icon="e.icon"
              :title="e.label"
              :description="e.hint"
              :data-test="`settings-entry-${e.key}`"
            />
          </template>
        </AppSettingsGroup>
      </div>
    </div>
  </div>
</template>

<style scoped>
.settings-page { max-inline-size: 1024px; }
.settings-search { inline-size: 300px; max-inline-size: 100%; }
.settings-layout { display: grid; grid-template-columns: 232px minmax(0, 1fr); gap: 32px; align-items: start; }
/* ADR-786 : une seule rubrique → pas de colonne de navigation ; le contenu occupe toute la largeur. */
.settings-layout--single { grid-template-columns: minmax(0, 1fr); }
.settings-nav { position: sticky; inset-block-start: 88px; display: flex; flex-direction: column; gap: 2px; }
.settings-nav__item {
  display: flex; align-items: center; gap: 10px; inline-size: 100%;
  padding: 7px 10px; border-radius: 8px; text-align: start; white-space: nowrap;
  font-size: 0.875rem; line-height: 1.3;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
  transition: background-color 0.12s ease, color 0.12s ease;
}
.settings-nav__item:hover { background: rgba(var(--v-theme-on-surface), 0.04); color: rgba(var(--v-theme-on-surface), var(--v-high-emphasis-opacity)); }
.settings-nav__item--active { background: rgba(var(--v-theme-primary), 0.08); color: rgb(var(--v-theme-primary)); font-weight: 500; }
.settings-nav__item:focus-visible { outline: 2px solid rgb(var(--v-theme-primary)); outline-offset: 1px; }
.settings-nav__label { flex: 1 1 auto; min-inline-size: 0; overflow: hidden; text-overflow: ellipsis; }
.settings-nav__count { font-size: 0.75rem; color: rgba(var(--v-theme-on-surface), var(--v-disabled-opacity)); }
.settings-content { min-inline-size: 0; }
.settings-empty { padding: 48px 16px; text-align: center; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
.settings-advanced-toggle {
  display: flex; align-items: center; justify-content: space-between; inline-size: 100%;
  padding: 12px 18px; font-size: 0.875rem;
  color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
}
.settings-advanced-toggle:hover { background: rgba(var(--v-theme-on-surface), 0.035); }

/* Mobile / tablette : les rubriques deviennent une barre défilante (le défilement reste DANS la barre). */
@media (max-width: 959px) {
  .settings-layout { grid-template-columns: minmax(0, 1fr); gap: 16px; }
  .settings-search { inline-size: 100%; }
  .settings-nav--bar {
    position: sticky; inset-block-start: 64px; z-index: 2; flex-direction: row; gap: 6px;
    overflow-x: auto; scrollbar-width: none; margin-inline: -4px; padding: 6px 4px;
    background: rgb(var(--v-theme-background));
  }
  .settings-nav--bar::-webkit-scrollbar { display: none; }
  .settings-nav--bar .settings-nav__item {
    inline-size: auto; flex: none; white-space: nowrap; padding: 6px 12px; border-radius: 999px;
    border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  }
  .settings-content { max-inline-size: none; }
}
</style>
