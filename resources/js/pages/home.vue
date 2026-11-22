<script setup>
// ADR-818 (chantier D) — LE SEUL accueil Company. Blocs conditionnés par les permissions effectives :
//   - MOI : mes actions, mon planning, mes pointages, mes documents, mes congés, mes livraisons (si j'ai un
//     emploi ou des livraisons) ;
//   - À TRAITER + ENTREPRISE : priorités, actions et indicateurs collectifs AUTORISÉS (ADR-816 : un droit
//     personnel n'ouvre aucun indicateur collectif) — pour qui a des entrées de gestion.
// `/dashboard` est un alias (redirection). Les dispositions enregistrées sont conservées telles quelles.
import { useAuthStore } from '@/core/stores/auth'
import { useWorkforceClockStore } from '@/core/stores/workforce-clock'
import CompanyHome from '@/components/home/CompanyHome.vue'
import PersonalHome from '@/components/home/PersonalHome.vue'

const auth = useAuthStore()
const clock = useWorkforceClockStore()

// Gestion = l'espace de travail serveur (« dashboard ») : décidé par les droits de gestion du rôle.
const hasManagement = computed(() => auth.workspace !== 'home')

// ADR-862 — the browser tab says where the user is : « Accueil · <company> · Leezr »
const { t } = useI18n()
watchEffect(() => { document.title = [t('l7.tabTitle.home'), auth.currentCompany?.name, 'Leezr'].filter(Boolean).join(' · ') })
</script>

<template>
  <CompanyHome v-if="hasManagement">
    <template #personal>
      <PersonalHome
        v-if="clock.employee"
        embedded
      />
    </template>
  </CompanyHome>
  <PersonalHome v-else />
</template>
