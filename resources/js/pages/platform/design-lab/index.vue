<script setup>
// Leezr 5.0 (L0, ADR-855) — THE single design entry point (devOnly : refused by the router in production builds).
// Gathers the references and the proposals ; the three historical labs are listed with what was recovered from
// them. Nothing is deleted before its useful content lives here (decision 08-roadmap L0).
import LabBanner from '@/components/design-lab/LabBanner.vue'
import AppStatusChip from '@/components/app/AppStatusChip.vue'

definePage({ meta: { layout: 'platform', platform: true, permission: 'view_dashboard', devOnly: true } })

const sections = [
  { title: 'Cockpit Platform (V35)', text: 'Écran de référence Leezr 5.0, connecté aux vraies données.', to: { name: 'platform-dashboard-tab', params: { tab: 'overview' } }, icon: 'tabler-layout-dashboard', tone: 'success', status: 'En production locale (L1)' },
  { title: 'Bibliothèque de widgets', text: 'Un même widget à cinq tailles réelles, états, tailles refusées, profils par famille.', to: '/platform/design-lab/widgets', icon: 'tabler-layout-grid', tone: 'primary', status: 'Référence L0' },
  { title: 'Composants partagés', text: 'En-têtes, actions, statuts, cartes, tiroirs, confirmations, états.', to: '/platform/design-lab/components', icon: 'tabler-components', tone: 'primary', status: 'Référence L0' },
  { title: 'Espace de travail Company (V22)', text: 'Proposition pour L3 — données fictives.', to: '/company/design-lab/workspace', icon: 'tabler-briefcase', tone: 'info', status: 'Proposition L3' },
  { title: 'Personnes', text: 'Proposition pour L5 — données fictives.', to: '/company/design-lab/people', icon: 'tabler-users', tone: 'info', status: 'Proposition L5' },
]
const legacy = [
  { path: '/ui-lab', adr: 'ADR-741', what: 'Couche Premium (.lzr-card flat/raised/active) — reprise dans « Composants partagés » et dans styles.scss.' },
  { path: '/platform/presentation-lab', adr: 'ADR-748', what: 'Matrice de tailles du rendu adaptatif — reprise dans « Bibliothèque de widgets » (§1).' },
  { path: '/company/dev-lab', adr: 'ADR-694', what: 'Laboratoire responsive Workforce — à reprendre en L3/L4 avant retrait.' },
]
</script>

<template>
  <div>
    <LabBanner name="Laboratoire de design (point d'entrée unique)" />
    <h1 class="text-h3 font-weight-bold mb-1">
      Laboratoire de design Leezr
    </h1>
    <p class="text-body-1 text-medium-emphasis mb-6">
      Un seul point d'entrée, en développement uniquement. Les composants vivent dans <code>components/app/</code> ;
      les jetons dans <code>styles.scss</code> (couche Premium).
    </p>
    <VRow class="mb-8">
      <VCol
        v-for="s in sections"
        :key="s.title"
        cols="12"
        md="6"
        lg="4"
      >
        <VCard
          :to="s.to"
          class="lzr-card lzr-card--raised pa-5 h-100"
        >
          <div class="d-flex align-center justify-space-between mb-3">
            <VAvatar
              color="primary"
              variant="tonal"
              rounded="lg"
            >
              <VIcon :icon="s.icon" />
            </VAvatar>
            <AppStatusChip
              :tone="s.tone"
              :label="s.status"
            />
          </div>
          <div class="text-h6">
            {{ s.title }}
          </div>
          <div class="text-body-2 text-medium-emphasis">
            {{ s.text }}
          </div>
        </VCard>
      </VCol>
    </VRow>
    <h2 class="text-h5 mb-3">
      Laboratoires historiques
    </h2>
    <VCard class="lzr-card lzr-card--flat">
      <VTable>
        <thead>
          <tr>
            <th>Adresse</th><th>Origine</th><th>Contenu repris</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="l in legacy"
            :key="l.path"
          >
            <td><code>{{ l.path }}</code></td><td>{{ l.adr }}</td><td>{{ l.what }}</td>
          </tr>
        </tbody>
      </VTable>
    </VCard>
  </div>
</template>
