<script setup>
// ─────────────────────────────────────────────────────────────────────────
// LEEZR PREMIUM — Page de référence (Phase 0, ADR-741).
// Prévisualise le futur cockpit Company avec la couche Lzr*.
// ⚠ TOUTES les données ci-dessous sont des FIXTURES DE DÉMONSTRATION,
//   explicitement identifiées — AUCUNE donnée métier réelle, AUCUN déploiement
//   dans les dashboards de production. Page isolée hors navigation.
// ─────────────────────────────────────────────────────────────────────────
import { ref } from 'vue'
import LzrPageHeader from '@/core/components/LzrPageHeader.vue'
import LzrStatStrip from '@/core/components/LzrStatStrip.vue'
import LzrKpiCard from '@/core/components/LzrKpiCard.vue'
import LzrWidgetFrame from '@/core/components/LzrWidgetFrame.vue'
import LzrApexChart from '@/core/components/LzrApexChart.vue'
import LzrGauge from '@/core/components/LzrGauge.vue'
import LzrTimelineCard from '@/core/components/LzrTimelineCard.vue'
import LzrEmptyState from '@/core/components/LzrEmptyState.vue'

const period = ref('30d')
const periods = [
  { value: '7d', label: '7 j' },
  { value: '30d', label: '30 j' },
  { value: 'mtd', label: 'Mois' },
]

// ── FIXTURES DÉMO ──────────────────────────────────────────────────────────
const kpis = [
  { label: 'Effectifs actifs', value: 50, icon: 'tabler-users', color: 'primary', delta: 4, caption: '+2 ce mois', spark: [44, 45, 46, 46, 48, 49, 50] },
  { label: 'Présents aujourd\'hui', value: '38 / 50', icon: 'tabler-clock-check', color: 'success', delta: -3, caption: '12 non pointés', spark: [41, 39, 42, 40, 38, 37, 38] },
  { label: 'Shifts non couverts', value: 3, icon: 'tabler-calendar-exclamation', color: 'warning', delta: 12, caption: 'Cette semaine', spark: [1, 2, 1, 4, 3, 5, 3] },
  { label: 'Congés à valider', value: 5, icon: 'tabler-beach', color: 'info', delta: null, caption: 'En attente', spark: [2, 3, 2, 4, 5, 4, 5] },
]

const plannedVsActual = [
  { name: 'Prévu', data: [320, 300, 340, 360, 330, 180, 90] },
  { name: 'Réel', data: [305, 295, 350, 340, 318, 160, 70] },
]
const weekCats = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim']

const clockLive = [31, 4, 15] // présents, pause, absents
const coverage = [{ name: 'Besoin', data: [8, 10, 9, 11, 7, 4, 2] }, { name: 'Assigné', data: [8, 9, 9, 9, 7, 3, 2] }]

const activity = [
  { title: 'Feuille de temps validée — M. Dupont', subtitle: 'Responsable exploitation', time: 'il y a 5 min', color: 'success', icon: 'tabler-check' },
  { title: 'Demande de congé déposée — A. Martin', subtitle: '3 jours (CP)', time: 'il y a 22 min', color: 'info', icon: 'tabler-beach' },
  { title: 'Anomalie de pointage détectée', subtitle: 'Shift sans clôture — Entrepôt A', time: 'il y a 1 h', color: 'warning', icon: 'tabler-alert-triangle' },
  { title: 'Signature complétée — contrat #4821', subtitle: 'Chauffeur', time: 'il y a 2 h', color: 'primary', icon: 'tabler-signature' },
]

// Toggle d'états (démo) loading / empty / error
const frameState = ref('loaded')
const states = [
  { value: 'loaded', label: 'Chargé' },
  { value: 'loading', label: 'Loading' },
  { value: 'empty', label: 'Empty' },
  { value: 'error', label: 'Error' },
]
</script>

<template>
  <div>
    <VAlert type="warning" variant="tonal" density="compact" class="mb-5" icon="tabler-flask">
      <strong>Page de référence — Design System Leezr Premium (Phase 0).</strong>
      Toutes les valeurs affichées sont des <strong>données de démonstration</strong>, jamais des données métier réelles.
    </VAlert>

    <LzrPageHeader
      v-model="period"
      title="Bonjour Djamel"
      subtitle="Aperçu du cockpit Company — Leezr Logistics (démo)"
      :periods="periods"
    >
      <template #actions>
        <VBtn color="primary" variant="tonal" prepend-icon="tabler-layout-grid-add" size="small">Personnaliser</VBtn>
      </template>
    </LzrPageHeader>

    <!-- Bandeau KPI -->
    <LzrStatStrip class="mb-6">
      <LzrKpiCard v-for="k in kpis" :key="k.label" v-bind="k" />
    </LzrStatStrip>

    <!-- Graphes principaux -->
    <VRow class="mb-2">
      <VCol cols="12" md="8">
        <LzrWidgetFrame title="Prévu vs Réel — heures travaillées" icon="tabler-chart-area-line" elevation="raised" :min-height="320" to="/dashboard">
          <LzrApexChart type="area" :series="plannedVsActual" :categories="weekCats" :height="240" />
        </LzrWidgetFrame>
      </VCol>
      <VCol cols="12" md="4">
        <LzrWidgetFrame title="Conformité documentaire" subtitle="Alerte — seuil critique" icon="tabler-shield-check" icon-color="error" elevation="raised" :min-height="320" to="/company/documents/overview">
          <LzrGauge :value="3" label="Conforme" invert :danger="50" :warn="80" :height="220" />
          <div class="text-center text-caption text-medium-emphasis">2 conformes / 66 — 64 manquants</div>
        </LzrWidgetFrame>
      </VCol>
    </VRow>

    <!-- Rangée opérationnelle -->
    <VRow class="mb-2">
      <VCol cols="12" md="4">
        <LzrWidgetFrame title="Pointage en direct" icon="tabler-clock-hour-4" elevation="raised" :min-height="280" to="/company/workforce/time">
          <LzrApexChart type="donut" :series="clockLive" :labels="['Présents', 'En pause', 'Absents']"
            :colors="['success', 'warning', 'error']" :height="230" />
        </LzrWidgetFrame>
      </VCol>
      <VCol cols="12" md="4">
        <LzrWidgetFrame title="Couverture planning" subtitle="Besoin vs assigné (semaine)" icon="tabler-calendar-stats" elevation="raised" :min-height="280" to="/company/workforce/planning">
          <LzrApexChart type="bar" :series="coverage" :categories="weekCats" :height="220" />
        </LzrWidgetFrame>
      </VCol>
      <VCol cols="12" md="4">
        <LzrTimelineCard :items="activity" :min-height="280" to="/company/audit" />
      </VCol>
    </VRow>

    <!-- Démonstration des états loading / empty / error -->
    <h2 class="text-h6 mt-6 mb-3">États standardisés (loading / empty / error)</h2>
    <VBtnToggle v-model="frameState" density="comfortable" variant="outlined" divided mandatory class="mb-3">
      <VBtn v-for="s in states" :key="s.value" :value="s.value" size="small">{{ s.label }}</VBtn>
    </VBtnToggle>
    <VRow>
      <VCol cols="12" md="4">
        <LzrWidgetFrame title="Widget — état loaded" icon="tabler-check" elevation="flat" :loading="frameState === 'loading'"
          :error="frameState === 'error' ? 'Impossible de charger les données' : false" :empty="frameState === 'empty'" :min-height="180">
          <div class="text-h4 text-primary">128</div>
          <div class="text-body-2 text-medium-emphasis">Exemple de contenu chargé</div>
        </LzrWidgetFrame>
      </VCol>
      <VCol cols="12" md="4">
        <LzrWidgetFrame title="Carte élévation « active »" icon="tabler-star" elevation="active" :min-height="180">
          <div class="text-body-2 text-medium-emphasis">Niveau d'élévation <strong>active</strong> (élément sélectionné).</div>
        </LzrWidgetFrame>
      </VCol>
      <VCol cols="12" md="4">
        <LzrWidgetFrame title="Empty state dédié" icon="tabler-inbox" elevation="flat" :min-height="180">
          <LzrEmptyState icon="tabler-calendar-plus" title="Aucun shift planifié" text="Commencez par créer un planning pour cette semaine." action-text="Créer un planning" to="/company/workforce/planning" />
        </LzrWidgetFrame>
      </VCol>
    </VRow>
  </div>
</template>
