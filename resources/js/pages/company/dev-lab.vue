<script setup>
// SMART RESPONSIVE UX 2026 (ADR-694) — LABORATOIRE de design system (DEV, hors navigation).
// Terrain d'essai INTERACTIF des primitives adaptatives : on fait varier la largeur du CONTENEUR
// (slider), le nombre d'actions, les permissions, les traductions longues — SANS perte d'état.
// Aucune donnée réelle : actions/données factices. Non référencé dans la nav (manifest-driven).
import { computed, ref } from 'vue'
import { useAppToast } from '@/composables/useAppToast'
import SmartActionBar from '@/core/components/SmartActionBar.vue'
import AppResponsiveDataView from '@/core/components/AppResponsiveDataView.vue'
import AppSectionNavigation from '@/core/components/AppSectionNavigation.vue'
import StatusChip from '@/core/components/StatusChip.vue'

definePage({ meta: { module: 'workforce', permission: 'workforce.view' } })

const { toast } = useAppToast()

// ── Contrôles ────────────────────────────────────────────────
const containerWidth = ref(720)
const actionCount = ref(5)
const longLabels = ref(false)
const withDanger = ref(true)
const withDisabled = ref(false)
const restrictOne = ref(false) // simule une action non autorisée (permission factice)

const L = (short, long) => (longLabels.value ? long : short)

// Jeu d'actions factice, trié par priorité. L'action principale est EXPLICITE (primary:true).
const allActions = computed(() => [
  { id: 'edit', label: L('Modifier', 'Modifier les informations'), icon: 'tabler-edit', priority: 100, primary: true, handler: () => toast('Action : Modifier', 'info') },
  { id: 'contract', label: L('Générer contrat', 'Générer un contrat de travail'), icon: 'tabler-file-text', priority: 80, group: 'docs', handler: () => toast('Action : Générer contrat', 'info') },
  { id: 'access', label: L('Gérer les accès', 'Gérer les accès au compte'), icon: 'tabler-key', priority: 70, group: 'access', handler: () => toast('Action : Gérer les accès', 'info') },
  { id: 'documents', label: L('Documents', 'Documents du collaborateur'), icon: 'tabler-folder', priority: 60, group: 'docs', handler: () => toast('Action : Documents', 'info') },
  { id: 'export', label: L('Exporter', 'Exporter la fiche (PDF)'), icon: 'tabler-file-export', priority: 50, group: 'docs', handler: () => toast('Action : Exporter', 'info') },
  { id: 'duplicate', label: L('Dupliquer', 'Dupliquer la configuration'), icon: 'tabler-copy', priority: 40, handler: () => toast('Action : Dupliquer', 'info') },
  { id: 'archive', label: L('Archiver', 'Archiver le collaborateur'), icon: 'tabler-archive', priority: 30, handler: () => toast('Action : Archiver', 'info') },
  { id: 'notify', label: L('Notifier', 'Envoyer une notification'), icon: 'tabler-bell', priority: 20, handler: () => toast('Action : Notifier', 'info') },
  { id: 'restricted', label: L('Restreint', 'Action restreinte (permission)'), icon: 'tabler-lock', priority: 15, permission: restrictOne.value ? 'does.not.exist' : undefined, handler: () => toast('Action : Restreint', 'info') },
  { id: 'delete', label: L('Supprimer', 'Préparer le départ / supprimer'), icon: 'tabler-trash', priority: 10, danger: true, handler: () => toast('Action dangereuse : Supprimer (confirmation gérée par le parent)', 'warning') },
])

const actions = computed(() => {
  const list = allActions.value.slice(0, actionCount.value)

  return list.map(a => ({
    ...a,
    disabled: withDisabled.value && a.id === 'archive',
    // retire l'action dangereuse si withDanger=false
  })).filter(a => withDanger.value || a.id !== 'delete')
})

const countPresets = [1, 3, 5, 10]

// ── AppResponsiveDataView (données factices) ─────────────────
const dvHeaders = [
  { title: 'Nom', key: 'name' },
  { title: 'Fonction', key: 'role' },
  { title: 'Statut', key: 'status', width: 120 },
  { title: '', key: 'actions', width: 60 },
]
const dvOptions = ref({ page: 1, itemsPerPage: 8, sortBy: [] })
const dvSelected = ref([])
const STATUSES = ['active', 'on_leave', 'suspended']
const dvItems = computed(() => Array.from({ length: 8 }, (_, i) => ({
  id: i + 1,
  name: ['Alex Martin', 'Camille Roux', 'Sofia Nkem', 'Lucas Bianchi', 'Yuki Tanaka', 'Omar Haddad', 'Lena Weber', 'Noa Silva'][i],
  role: ['Chauffeur', 'Serveuse', 'Coiffeur', 'Agent', 'Préparateur', 'Manager', 'Esthéticienne', 'Livreur'][i],
  status: STATUSES[i % 3],
})))

// ── AppSectionNavigation ─────────────────────────────────────
const sectionCount = ref(6)
const sectionPresets = [2, 3, 5, 8]
const allSections = [
  { value: 'overview', label: "Vue d'ensemble", icon: 'tabler-layout-dashboard' },
  { value: 'profile', label: 'Profil', icon: 'tabler-user' },
  { value: 'contract', label: 'Contrats', icon: 'tabler-file-text' },
  { value: 'planning', label: 'Planning', icon: 'tabler-calendar' },
  { value: 'time', label: 'Pointages', icon: 'tabler-clock' },
  { value: 'leave', label: 'Congés', icon: 'tabler-beach' },
  { value: 'documents', label: 'Documents', icon: 'tabler-file-certificate' },
  { value: 'history', label: 'Historique', icon: 'tabler-history' },
]
const sections = computed(() => allSections.slice(0, sectionCount.value))
const activeSection = ref('overview')
</script>

<template>
  <div>
    <div class="mb-6">
      <VChip color="warning" variant="tonal" size="small" class="mb-2">
        <VIcon icon="tabler-flask" start size="16" /> Dev UI Lab — non exposé
      </VChip>
      <h4 class="text-h4 font-weight-bold mb-0">
        Smart Responsive UX — Laboratoire
      </h4>
      <p class="text-body-1 text-medium-emphasis mb-0">
        Teste les primitives adaptatives en faisant varier la largeur du conteneur, les actions et les permissions — sans perte d'état.
      </p>
    </div>

    <!-- ══════════ SmartActionBar ══════════ -->
    <VCard class="mb-6">
      <VCardItem>
        <VCardTitle>SmartActionBar</VCardTitle>
        <VCardSubtitle>Command bar à overflow piloté par la largeur RÉELLE du conteneur (ResizeObserver)</VCardSubtitle>
      </VCardItem>
      <VDivider />
      <VCardText>
        <!-- Contrôles -->
        <VRow class="mb-2">
          <VCol cols="12" md="6">
            <label class="text-caption font-weight-medium d-block mb-1">
              Largeur du conteneur : <strong>{{ containerWidth }}px</strong>
            </label>
            <VSlider
              v-model="containerWidth"
              :min="200"
              :max="1100"
              :step="10"
              color="primary"
              hide-details
              data-test="lab-width"
            />
          </VCol>
          <VCol cols="12" md="6">
            <label class="text-caption font-weight-medium d-block mb-1">Nombre d'actions</label>
            <VBtnToggle
              v-model="actionCount"
              mandatory
              density="compact"
              color="primary"
              variant="outlined"
              data-test="lab-count"
            >
              <VBtn v-for="c in countPresets" :key="c" :value="c">
                {{ c }}
              </VBtn>
            </VBtnToggle>
          </VCol>
        </VRow>
        <div class="d-flex gap-4 flex-wrap mb-4">
          <VSwitch v-model="longLabels" label="Traductions longues" density="compact" hide-details color="primary" data-test="lab-long" />
          <VSwitch v-model="withDanger" label="Action dangereuse" density="compact" hide-details color="primary" />
          <VSwitch v-model="withDisabled" label="Action désactivée" density="compact" hide-details color="primary" />
          <VSwitch v-model="restrictOne" label="Une action non autorisée" density="compact" hide-details color="primary" />
        </div>

        <!-- Conteneur redimensionnable : la barre s'adapte à SA largeur, pas au viewport -->
        <div class="lab-resize-track pa-4 rounded">
          <div
            class="lab-resizable"
            :style="{ inlineSize: containerWidth + 'px' }"
            data-test="lab-sab-container"
          >
            <div class="d-flex align-center text-caption text-medium-emphasis mb-2">
              <VIcon icon="tabler-arrows-horizontal" size="14" class="me-1" /> {{ containerWidth }}px
            </div>
            <SmartActionBar
              :actions="actions"
              class="pa-2 rounded bg-surface border"
            />
          </div>
        </div>

        <VAlert type="info" variant="tonal" density="compact" class="mt-4">
          Réduis la largeur : les actions secondaires basculent automatiquement dans « ··· ».
          L'action principale (Modifier) reste toujours visible. Le menu overflow est accessible au clavier.
        </VAlert>
      </VCardText>
    </VCard>

    <!-- ══════════ AppResponsiveDataView ══════════ -->
    <VCard class="mb-6">
      <VCardItem>
        <VCardTitle>AppResponsiveDataView</VCardTitle>
        <VCardSubtitle>Table desktop / liste tactile mobile — mêmes données, mêmes règles (redimensionne la fenêtre)</VCardSubtitle>
      </VCardItem>
      <VDivider />
      <VCardText>
        <AppResponsiveDataView
          v-model="dvSelected"
          v-model:options="dvOptions"
          show-select
          :headers="dvHeaders"
          :items="dvItems"
          :items-length="dvItems.length"
          @row-click="item => toast(`Détail : ${item.name}`, 'info')"
        >
          <template #mobile-item="{ item }">
            <div class="d-flex align-center gap-3" style="min-inline-size:0;">
              <VAvatar color="primary" variant="tonal" size="40">
                <span class="text-sm">{{ item.name.split(' ').map(w => w[0]).join('') }}</span>
              </VAvatar>
              <div class="flex-grow-1" style="min-inline-size:0;">
                <div class="font-weight-medium text-truncate">
                  {{ item.name }}
                </div>
                <div class="text-caption text-medium-emphasis text-truncate">
                  {{ item.role }}
                </div>
              </div>
              <StatusChip :status="item.status" domain="employee" size="small">
                {{ item.status }}
              </StatusChip>
            </div>
          </template>
          <template #item.name="{ item }">
            <span class="font-weight-medium">{{ item.name }}</span>
          </template>
          <template #item.status="{ item }">
            <StatusChip :status="item.status" domain="employee">
              {{ item.status }}
            </StatusChip>
          </template>
          <template #item.actions>
            <VIcon icon="tabler-chevron-right" class="text-disabled" />
          </template>
        </AppResponsiveDataView>
        <VAlert type="info" variant="tonal" density="compact" class="mt-4">
          En dessous de ~960px, la table devient une liste tactile ; le détail s'ouvre au toucher. Sélection, tri et pagination restent partagés.
        </VAlert>
      </VCardText>
    </VCard>

    <!-- ══════════ AppSectionNavigation ══════════ -->
    <VCard class="mb-6">
      <VCardItem>
        <VCardTitle>AppSectionNavigation</VCardTitle>
        <VCardSubtitle>Choisit le meilleur pattern selon le nombre de sections et la largeur</VCardSubtitle>
      </VCardItem>
      <VDivider />
      <VCardText>
        <label class="text-caption font-weight-medium d-block mb-1">Nombre de sections</label>
        <VBtnToggle
          v-model="sectionCount"
          mandatory
          density="compact"
          color="primary"
          variant="outlined"
          class="mb-4"
          data-test="lab-sections"
        >
          <VBtn v-for="c in sectionPresets" :key="c" :value="c">
            {{ c }}
          </VBtn>
        </VBtnToggle>
        <AppSectionNavigation
          v-model="activeSection"
          :sections="sections"
        />
        <VAlert type="info" variant="tonal" density="compact" class="mt-4">
          Desktop : onglets. Mobile : ≤3 → segmented · 4-5 → onglets scrollables · &gt;5 → sélecteur. Section active : <strong>{{ activeSection }}</strong>.
        </VAlert>
      </VCardText>
    </VCard>
  </div>
</template>

<style lang="scss">
.lab-resize-track {
  background: rgba(var(--v-theme-on-surface), 0.04);
  border: 1px dashed rgba(var(--v-theme-on-surface), 0.12);
  overflow-x: auto;
}
.lab-resizable {
  max-inline-size: 100%;
  transition: inline-size 0.08s linear;
}
</style>
