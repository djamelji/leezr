<script setup>
// Leezr 5.0 (L0) — shared components proposal (one visual language for Platform and Company).
// Built only from Vuetify/Vuexy primitives + the existing Leezr Premium layer (.lzr-card, ADR-741). Prototype.
import LabBanner from '@/components/design-lab/LabBanner.vue'

definePage({ meta: { layout: 'platform', platform: true, permission: 'view_dashboard', devOnly: true } })

const drawerStep = ref(1)
const confirm = ref(false)
</script>

<template>
  <div class="lz-ds">
    <LabBanner
      name="Composants partagés"
      compare="/ui-lab"
    />
    <h1 class="text-h3 font-weight-bold mb-1">
      Langage visuel commun
    </h1>
    <p class="text-body-1 text-medium-emphasis mb-8">
      Mêmes briques pour Platform (cockpit) et Company (espace de travail). Couleurs = jetons du thème, jamais codées en dur.
    </p>

    <!-- 1. Page header -->
    <section class="mb-10">
      <div class="lz-h">
        1 · En-tête de page
      </div>
      <VCard class="lzr-card lzr-card--flat pa-6">
        <div class="d-flex flex-wrap align-center gap-3">
          <div class="flex-grow-1">
            <div class="text-caption text-medium-emphasis mb-1">
              Facturation › Abonnements
            </div>
            <div class="text-h4 font-weight-bold lz-tight">
              Abonnements <span class="text-medium-emphasis font-weight-regular">· 26</span>
            </div>
            <div class="text-body-1 text-medium-emphasis">
              Une phrase utile : ce que la page permet de faire.
            </div>
          </div>
          <VBtn variant="text">
            Exporter
          </VBtn>
          <VBtn
            variant="tonal"
            color="secondary"
          >
            Filtres enregistrés
          </VBtn>
          <VBtn color="primary">
            Action principale
          </VBtn>
        </div>
        <div class="text-caption text-medium-emphasis mt-4">
          Règle : 1 action principale (pleine), 1 secondaire (tonale), le reste en texte ou dans le menu « ⋯ ».
          Plus de bandeau d'explication permanent : l'aide passe par le titre, le sous-titre et l'aide contextuelle.
        </div>
      </VCard>
    </section>

    <!-- 2. Buttons + pills -->
    <section class="mb-10">
      <div class="lz-h">
        2 · Actions et statuts
      </div>
      <VRow>
        <VCol
          cols="12"
          md="6"
        >
          <VCard class="lzr-card lzr-card--flat pa-6 h-100">
            <div class="d-flex flex-wrap gap-3 mb-4">
              <VBtn color="primary">
                Principale
              </VBtn>
              <VBtn
                variant="tonal"
                color="primary"
              >
                Secondaire
              </VBtn>
              <VBtn variant="text">
                Tertiaire
              </VBtn>
              <VBtn
                variant="tonal"
                color="error"
                prepend-icon="tabler-trash"
              >
                Destructive
              </VBtn>
            </div>
            <div class="d-flex flex-wrap gap-3">
              <VBtn
                color="primary"
                loading
              >
                En cours
              </VBtn>
              <VBtn
                color="primary"
                disabled
              >
                Indisponible
              </VBtn>
              <VBtn
                icon="tabler-dots-vertical"
                variant="text"
              />
            </div>
          </VCard>
        </VCol>
        <VCol
          cols="12"
          md="6"
        >
          <VCard class="lzr-card lzr-card--flat pa-6 h-100">
            <div class="d-flex flex-wrap gap-2 mb-4">
              <VChip
                v-for="p in [['success', 'Actif'], ['info', 'Invité'], ['primary', 'En intégration'], ['warning', 'À valider'], ['error', 'En retard'], ['secondary', 'Archivé']]"
                :key="p[1]"
                :color="p[0]"
                variant="tonal"
                label
                size="small"
              >
                {{ p[1] }}
              </VChip>
            </div>
            <div class="text-caption text-medium-emphasis">
              Une couleur = un sens, partout : vert = bon/terminé, bleu = information/en attente d'autrui,
              violet = en cours chez nous, orange = action attendue, rouge = bloquant, gris = inactif.
            </div>
          </VCard>
        </VCol>
      </VRow>
    </section>

    <!-- 3. Cards -->
    <section class="mb-10">
      <div class="lz-h">
        3 · Cartes : trois niveaux
      </div>
      <VRow>
        <VCol
          v-for="c in [['lzr-card--flat', 'Plate', 'Conteneurs, sections, tableaux'], ['lzr-card--raised', 'Surélevée', 'Widgets et KPI · se soulève au survol'], ['lzr-card--active', 'Active', 'Élément sélectionné']]"
          :key="c[0]"
          cols="12"
          md="4"
        >
          <VCard
            class="lzr-card pa-6"
            :class="c[0]"
          >
            <div class="text-h6">
              {{ c[1] }}
            </div>
            <div class="text-body-2 text-medium-emphasis">
              {{ c[2] }}
            </div>
            <code class="text-caption">.{{ c[0] }}</code>
          </VCard>
        </VCol>
      </VRow>
      <div class="text-caption text-medium-emphasis mt-2">
        Rayon 12 px (cartes) · 16–20 px (héros, panneaux) · 10 px (contrôles) · espacements 4/8/12/16/24/32.
      </div>
    </section>

    <!-- 4. Drawer anatomy + confirm -->
    <section class="mb-10">
      <div class="lz-h">
        4 · Tiroir et confirmation
      </div>
      <VRow>
        <VCol
          cols="12"
          md="6"
        >
          <VCard class="lzr-card lzr-card--raised lz-drawer-mock">
            <div class="lz-drawer-mock__head">
              <div>
                <div class="text-h6">
                  Ajouter une personne
                </div>
                <div class="text-body-2 text-medium-emphasis">
                  Étape {{ drawerStep }}/3 · Identité
                </div>
              </div>
              <VBtn
                icon="tabler-x"
                variant="text"
                size="small"
              />
            </div>
            <VProgressLinear
              :model-value="drawerStep * 33.3"
              color="primary"
              height="3"
            />
            <div class="pa-5 d-flex flex-column gap-4">
              <AppTextField
                label="Prénom et nom"
                placeholder="Camille Laurent"
              />
              <AppSelect
                label="Poste"
                :items="['Chauffeur-livreur', 'Préparatrice']"
                model-value="Chauffeur-livreur"
              />
              <div class="text-caption text-medium-emphasis">
                Les champs demandés viennent du poste choisi — rien à configurer ici.
              </div>
            </div>
            <div class="lz-drawer-mock__foot">
              <VBtn variant="text">
                Annuler
              </VBtn>
              <VSpacer />
              <VBtn
                color="primary"
                @click="drawerStep = drawerStep % 3 + 1"
              >
                Continuer
              </VBtn>
            </div>
          </VCard>
        </VCol>
        <VCol
          cols="12"
          md="6"
        >
          <VCard class="lzr-card lzr-card--flat pa-6 h-100 d-flex flex-column gap-3">
            <div class="text-body-2">
              Tiroir = <strong>créer ou modifier un objet</strong> sans quitter la liste : en-tête fixe, progression,
              corps défilant, pied fixe avec une seule action principale. Modale = <strong>confirmer</strong> une
              décision, jamais un formulaire long.
            </div>
            <VBtn
              variant="tonal"
              color="error"
              class="align-self-start"
              @click="confirm = true"
            >
              Ouvrir la confirmation
            </VBtn>
            <VAlert
              class="flex-grow-0"
              type="success"
              variant="tonal"
              density="compact"
              icon="tabler-circle-check"
            >
              Retour après action : notification brève, avec « Annuler » quand c'est réversible.
            </VAlert>
          </VCard>
        </VCol>
      </VRow>
      <VDialog
        v-model="confirm"
        max-width="440"
      >
        <VCard class="pa-6">
          <VAvatar
            color="error"
            variant="tonal"
            size="48"
            class="mb-3"
          >
            <VIcon icon="tabler-alert-triangle" />
          </VAvatar>
          <div class="text-h6 mb-1">
            Suspendre Coursiers Express ?
          </div>
          <div class="text-body-2 text-medium-emphasis mb-5">
            Les 6 utilisateurs perdent l'accès immédiatement. Les données sont conservées et l'export reste possible.
          </div>
          <div class="d-flex gap-2 justify-end">
            <VBtn
              variant="text"
              @click="confirm = false"
            >
              Annuler
            </VBtn>
            <VBtn
              color="error"
              @click="confirm = false"
            >
              Suspendre
            </VBtn>
          </div>
        </VCard>
      </VDialog>
    </section>

    <!-- 5. States -->
    <section class="mb-6">
      <div class="lz-h">
        5 · États vides, chargement, erreur
      </div>
      <VRow>
        <VCol
          cols="12"
          md="4"
        >
          <VCard class="lzr-card lzr-card--flat pa-6 text-center h-100">
            <VAvatar
              color="primary"
              variant="tonal"
              size="56"
              class="mb-3"
            >
              <VIcon
                icon="tabler-truck"
                size="28"
              />
            </VAvatar>
            <div class="text-h6">
              Aucune expédition aujourd'hui
            </div>
            <div class="text-body-2 text-medium-emphasis mb-4">
              Dites ce qui manque et proposez l'action suivante.
            </div>
            <VBtn
              color="primary"
              size="small"
            >
              Créer une expédition
            </VBtn>
          </VCard>
        </VCol>
        <VCol
          cols="12"
          md="4"
        >
          <VCard class="lzr-card lzr-card--flat pa-6 h-100">
            <VSkeletonLoader
              type="list-item-avatar-two-line@3"
              class="bg-transparent"
            />
            <div class="text-caption text-medium-emphasis mt-2">
              Squelettes à la forme du contenu, jamais d'indicateur de chargement plein écran.
            </div>
          </VCard>
        </VCol>
        <VCol
          cols="12"
          md="4"
        >
          <VCard class="lzr-card lzr-card--flat pa-6 text-center h-100">
            <VAvatar
              color="error"
              variant="tonal"
              size="56"
              class="mb-3"
            >
              <VIcon
                icon="tabler-plug-connected-x"
                size="28"
              />
            </VAvatar>
            <div class="text-h6">
              Impossible de charger les factures
            </div>
            <div class="text-body-2 text-medium-emphasis mb-4">
              Message humain, référence technique en petit, action « Réessayer ».
            </div>
            <VBtn
              variant="tonal"
              color="error"
              size="small"
              prepend-icon="tabler-refresh"
            >
              Réessayer
            </VBtn>
          </VCard>
        </VCol>
      </VRow>
    </section>
  </div>
</template>

<style scoped>
.lz-h { font-size: .8125rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: rgba(var(--v-theme-on-surface), .55); margin-block-end: .75rem; }
.lz-tight { letter-spacing: -.03em; }
.lz-drawer-mock { overflow: hidden; }
.lz-drawer-mock__head { display: flex; align-items: flex-start; justify-content: space-between; padding: 1.25rem 1.25rem 1rem; }
.lz-drawer-mock__foot { display: flex; align-items: center; padding: .875rem 1.25rem; border-block-start: 1px solid rgba(var(--v-border-color), .12); background: rgba(var(--v-theme-on-surface), .02); }
</style>
