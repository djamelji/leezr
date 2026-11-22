<script setup>
// Leezr 5.0 (L0) — widget library proposal: the SAME widget at real container sizes (the content is
// recomposed per presentation mode, measured by the existing engine), its states, and the sizes a family refuses.
// Prototype: fictitious data.
import LabBanner from '@/components/design-lab/LabBanner.vue'
import AppKpiTile from '@/components/app/AppKpiTile.vue'
import LabTrendDemo from '@/components/design-lab/LabTrendDemo.vue'

definePage({ meta: { layout: 'platform', platform: true, permission: 'view_dashboard', devOnly: true } })

const trendSizes = [
  { label: 'Petite · micro', w: 300, h: 170 },
  { label: 'Moyenne · compact', w: 420, h: 300 },
  { label: 'Moyenne · standard', w: 620, h: 320 },
  { label: 'Grande · wide', w: 940, h: 270 },
  { label: 'Très grande · expanded', w: 1100, h: 420 },
]

const kpiSizes = [
  { label: '170 × 130', w: 170, h: 130 },
  { label: '250 × 150', w: 250, h: 150 },
  { label: '360 × 150', w: 360, h: 150 },
  { label: '560 × 230', w: 560, h: 230 },
]

const families = [
  { family: 'KPI', ex: 'MRR, livraisons du jour, présents', min: '2 × 2', def: '3 × 2', max: '6 × 3', forbids: 'hauteur > 3 (contenu étiré)' },
  { family: 'Tendance', ex: 'MRR 12 mois, heures pointées', min: '3 × 2', def: '6 × 4', max: '12 × 6', forbids: 'largeur < 3' },
  { family: 'Liste / file', ex: 'À traiter, essais, factures', min: '3 × 3', def: '4 × 4', max: '6 × 8', forbids: 'largeur > 6 (lignes trop longues)' },
  { family: 'Répartition', ex: 'Offres, état des livraisons', min: '3 × 3', def: '4 × 4', max: '6 × 5', forbids: 'hauteur < 3' },
  { family: 'Supervision', ex: 'Santé plateforme, pointage en direct', min: '4 × 4', def: '4 × 6', max: '6 × 8', forbids: 'micro / compact' },
  { family: 'Équipe', ex: 'Équipe en ce moment, chauffeurs actifs', min: '3 × 3', def: '4 × 4', max: '12 × 4', forbids: 'hauteur > 5' },
]
</script>

<template>
  <div>
    <LabBanner
      name="Bibliothèque de widgets"
      compare="/platform/presentation-lab"
    />
    <h1 class="text-h3 font-weight-bold mb-1">
      Bibliothèque de widgets
    </h1>
    <p class="text-body-1 text-medium-emphasis mb-6">
      Un widget se recompose selon la taille réelle de son conteneur ; il ne rapetisse jamais son contenu dans un coin.
    </p>

    <h2 class="text-h5 mb-1">
      1. Un même widget, cinq tailles
    </h2>
    <p class="text-body-2 text-medium-emphasis mb-4">
      Tendance « MRR » placée dans des conteneurs de tailles fixes. Mode mesuré par le moteur existant (ADR-748).
    </p>
    <div class="lz-sizes mb-8">
      <div
        v-for="s in trendSizes"
        :key="s.label"
      >
        <div class="text-caption text-medium-emphasis mb-1">
          {{ s.label }} · {{ s.w }} × {{ s.h }} px
        </div>
        <div :style="{ inlineSize: `${s.w}px`, blockSize: `${s.h}px`, maxInlineSize: '100%' }">
          <LabTrendDemo />
        </div>
      </div>
    </div>

    <h2 class="text-h5 mb-1">
      2. Tuile KPI — profil de taille propre
    </h2>
    <p class="text-body-2 text-medium-emphasis mb-4">
      Les seuils des graphiques (micro sous 210 px de haut) laissent toute tuile KPI en « micro ». Chaque famille
      reçoit son propre profil, avec la même mesure.
    </p>
    <div class="lz-sizes lz-sizes--row mb-8">
      <div
        v-for="s in kpiSizes"
        :key="s.label"
      >
        <div class="text-caption text-medium-emphasis mb-1">
          {{ s.label }} px
        </div>
        <div :style="{ inlineSize: `${s.w}px`, blockSize: `${s.h}px`, maxInlineSize: '100%' }">
          <AppKpiTile
            label="Livraisons du jour"
            icon="tabler-truck-delivery"
            :value="14"
            :delta="12"
            delta-label="vs lundi dernier"
            context="9 terminées · 2 en retard · 2 non assignées"
            :spark="[9, 11, 10, 12, 13, 12, 14]"
            spark-type="bar"
          />
        </div>
      </div>
    </div>

    <h2 class="text-h5 mb-1">
      3. États : chargement, vide, erreur
    </h2>
    <p class="text-body-2 text-medium-emphasis mb-4">
      Même enveloppe, même hauteur : l'interface ne « saute » pas quand les données arrivent.
    </p>
    <VRow class="mb-8">
      <VCol
        v-for="st in ['loading', 'empty', 'error']"
        :key="st"
        cols="12"
        md="4"
      >
        <div style="block-size: 280px;">
          <LabTrendDemo :state="st" />
        </div>
      </VCol>
    </VRow>

    <h2 class="text-h5 mb-1">
      4. Tailles refusées
    </h2>
    <p class="text-body-2 text-medium-emphasis mb-4">
      Une famille déclare les tailles qu'elle ne sait pas rendre utilement : la grille les refuse au redimensionnement
      plutôt que d'afficher un rendu cassé.
    </p>
    <div class="d-flex flex-wrap gap-6 align-start mb-8">
      <div class="lz-refused">
        <VIcon
          icon="tabler-ban"
          color="error"
        />
        <div class="font-weight-medium">
          Supervision en 2 × 2
        </div>
        <div class="text-caption text-medium-emphasis">
          Taille minimale : 4 × 4. La poignée s'arrête à la limite.
        </div>
      </div>
      <VTable
        density="compact"
        class="lz-table flex-grow-1"
      >
        <thead>
          <tr>
            <th>Famille</th><th>Exemples</th><th>Min</th><th>Défaut</th><th>Max</th><th>Refuse</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="f in families"
            :key="f.family"
          >
            <td class="font-weight-medium">
              {{ f.family }}
            </td><td>{{ f.ex }}</td><td>{{ f.min }}</td><td>{{ f.def }}</td><td>{{ f.max }}</td><td>{{ f.forbids }}</td>
          </tr>
        </tbody>
      </VTable>
    </div>
    <p class="text-caption text-medium-emphasis">
      Unités de grille : colonnes sur 12 × lignes de 80 px (grille actuelle du tableau de bord).
    </p>
  </div>
</template>

<style scoped>
.lz-sizes { display: flex; flex-direction: column; gap: 1.5rem; }
.lz-sizes--row { flex-direction: row; flex-wrap: wrap; align-items: flex-end; }
.lz-refused { inline-size: 240px; block-size: 160px; border-radius: 16px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .375rem; text-align: center; padding: 1rem; border: 2px dashed rgba(var(--v-theme-error), .45); background: repeating-linear-gradient(45deg, rgba(var(--v-theme-error), .05) 0 10px, transparent 10px 20px); }
.lz-table { border-radius: 14px; border: 1px solid rgba(var(--v-border-color), .12); }
</style>
