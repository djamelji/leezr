<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useDisplay } from 'vuetify'
import { useNavStore } from '@/core/stores/nav'
import NavBarNotifications from '@/layouts/components/NavBarNotifications.vue'
import NotificationToast from '@/layouts/components/NotificationToast.vue'
import NavbarShortcuts from '@/layouts/components/NavbarShortcuts.vue'
import NavBarSupport from '@/layouts/components/NavBarSupport.vue'
import NavbarThemeSwitcher from '@/layouts/components/NavbarThemeSwitcher.vue'
import WorkforceClockWidget from '@/layouts/components/WorkforceClockWidget.vue'
import NavBarI18n from '@core/components/I18n.vue'
import { themeConfig } from '@themeConfig'

const route = useRoute()
const navStore = useNavStore()

// E3-R / R2 — app bar mobile : sous md, les OUTILS SECONDAIRES (langue, thème, apps, aide)
// passent dans un menu « plus ». Restent VISIBLES : pointeuse (action primaire employé) et
// notifications. Rien n'est supprimé — tout reste accessible. Desktop inchangé.
const { mdAndUp } = useDisplay()

// ADR-161: Component registry — maps backend component key → Vue component
const widgetComponents = {
  NavbarThemeSwitcher,
}

// ADR-161: Dynamic widgets from module capabilities (filtered by backend)
const activeWidgets = computed(() => {
  const widgets = route.meta?.platform
    ? navStore.platformWidgets
    : navStore.companyWidgets

  return (widgets || [])
    .filter(w => widgetComponents[w.component])
    .sort((a, b) => a.sortOrder - b.sortOrder)
})

const i18nEnabled = computed(() => themeConfig.app.i18n.enable && themeConfig.app.i18n.langConfig?.length)
</script>

<template>
  <!-- Pointeuse : action primaire employé — toujours visible (se masque elle-même hors contexte) -->
  <WorkforceClockWidget />

  <!-- Desktop/tablette large : tous les outils inline -->
  <template v-if="mdAndUp">
    <NavBarI18n
      v-if="i18nEnabled"
      :languages="themeConfig.app.i18n.langConfig"
    />
    <component
      v-for="widget in activeWidgets"
      :key="widget.key"
      :is="widgetComponents[widget.component]"
    />
    <NavbarShortcuts />
    <NavBarSupport />
  </template>

  <!-- Mobile : outils secondaires regroupés dans un menu « plus » (reste accessible, cibles ≥44px) -->
  <IconBtn
    v-else
    aria-label="Plus d'outils"
    data-test="navbar-more"
  >
    <VIcon icon="tabler-dots-vertical" />
    <VMenu
      activator="parent"
      location="bottom end"
      :close-on-content-click="false"
      offset="8"
    >
      <VList
        density="comfortable"
        min-width="200"
        class="pa-1"
      >
        <VListItem v-if="i18nEnabled">
          <template #prepend>
            <NavBarI18n :languages="themeConfig.app.i18n.langConfig" />
          </template>
          <VListItemTitle>{{ $t('navbar.language', 'Langue') }}</VListItemTitle>
        </VListItem>
        <VListItem
          v-for="widget in activeWidgets"
          :key="widget.key"
        >
          <template #prepend>
            <component :is="widgetComponents[widget.component]" />
          </template>
          <VListItemTitle>{{ $t('navbar.theme', 'Thème') }}</VListItemTitle>
        </VListItem>
        <VListItem>
          <template #prepend>
            <NavbarShortcuts />
          </template>
          <VListItemTitle>{{ $t('navbar.shortcuts', 'Raccourcis') }}</VListItemTitle>
        </VListItem>
        <VListItem>
          <template #prepend>
            <NavBarSupport />
          </template>
          <VListItemTitle>{{ $t('navbar.help', 'Aide') }}</VListItemTitle>
        </VListItem>
      </VList>
    </VMenu>
  </IconBtn>

  <!-- Notifications : prioritaires — toujours visibles -->
  <NavBarNotifications class="me-1" />
  <NotificationToast />
</template>
