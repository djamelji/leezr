<script setup>
// Leezr 5.0 (L0) — Company › Personnes proposal. Prototype: fictitious data, nothing connected.
// Decisions shown (docs/bmad/leezr-5-design/05-obsolete-ux.md §1):
//  - the « Outils › Champs du profil » drawer disappears from People: fields are governed in ONE place
//    (Réglages › Catalogues), obligation is set by POSITION (ADR-819), never company-wide from the people list;
//  - the list answers « who needs what » : segments with counts, completeness, next action per person;
//  - a quick-view drawer replaces a full page jump for the common checks.
import LabBanner from '@/components/design-lab/LabBanner.vue'

definePage({ meta: { devOnly: true } })

const route = useRoute()
const segment = ref('all')
const view = ref('list')
const q = ref('')
const preview = ref(route.query.preview ? 'alice' : null)

const people = [
  { id: 'alice', name: 'Alice Martin', i: 'AM', position: 'Dispatcheuse', dept: 'Exploitation', access: 'active', role: 'Dispatcher', contract: 'CDI', complete: 100, next: null, presence: 'success' },
  { id: 'karim', name: 'Karim Mansour', i: 'KM', position: 'Chauffeur-livreur', dept: 'Livraison', access: 'active', role: 'Chauffeur', contract: 'CDI', complete: 86, next: { label: 'Permis à renouveler', tone: 'warning', icon: 'tabler-id' }, presence: 'success' },
  { id: 'antoine', name: 'Antoine Mercier', i: 'AM', position: 'Chauffeur-livreur', dept: 'Livraison', access: 'none', role: null, contract: 'CDD', complete: 62, next: { label: 'Inviter sur Leezr', tone: 'primary', icon: 'tabler-send' }, presence: null },
  { id: 'camille', name: 'Camille Laurent', i: 'CL', position: 'Préparatrice', dept: 'Entrepôt', access: 'none', role: null, contract: 'CDI', complete: 40, next: { label: '3 infos manquantes', tone: 'error', icon: 'tabler-forms' }, presence: null },
  { id: 'jules', name: 'Jules Petit', i: 'JP', position: 'Chauffeur-livreur', dept: 'Livraison', access: 'pending', role: 'Chauffeur', contract: 'Intérim', complete: 74, next: { label: 'Invitation non acceptée', tone: 'info', icon: 'tabler-mail-forward' }, presence: null },
  { id: 'nadia', name: 'Nadia Amrani', i: 'NA', position: 'Cheffe d\'équipe', dept: 'Livraison', access: 'active', role: 'Manager', contract: 'CDI', complete: 100, next: null, presence: 'success' },
  { id: 'emma', name: 'Emma Fabre', i: 'EF', position: 'Assistante RH', dept: 'Direction', access: 'active', role: 'RH', contract: 'CDI', complete: 92, next: { label: 'En congé jusqu\'au 27', tone: 'secondary', icon: 'tabler-beach' }, presence: 'secondary' },
  { id: 'new', name: 'Léa Roux', i: 'LR', position: 'Chauffeur-livreur', dept: 'Livraison', access: 'onboarding', role: 'Chauffeur', contract: 'CDD', complete: 55, next: { label: 'Intégration · étape 2/4', tone: 'primary', icon: 'tabler-rocket' }, presence: null },
]

const segments = computed(() => [
  { key: 'all', label: 'Tous', n: people.length },
  { key: 'action', label: 'À traiter', n: people.filter(p => p.next && p.next.tone !== 'secondary').length, tone: 'error' },
  { key: 'none', label: 'Sans accès', n: people.filter(p => p.access === 'none').length },
  { key: 'onboarding', label: 'En intégration', n: people.filter(p => p.access === 'onboarding').length },
  { key: 'incomplete', label: 'Fiche incomplète', n: people.filter(p => p.complete < 100).length },
])

const rows = computed(() => people.filter(p => {
  if (q.value && !`${p.name} ${p.position} ${p.dept}`.toLowerCase().includes(q.value.toLowerCase())) return false
  if (segment.value === 'action') return p.next && p.next.tone !== 'secondary'
  if (segment.value === 'none') return p.access === 'none'
  if (segment.value === 'onboarding') return p.access === 'onboarding'
  if (segment.value === 'incomplete') return p.complete < 100

  return true
}))

const accessPill = a => ({
  active: { text: 'Accès actif', color: 'success' },
  none: { text: 'Sans accès', color: 'secondary' },
  pending: { text: 'Invité', color: 'info' },
  onboarding: { text: 'En intégration', color: 'primary' },
})[a]

const groups = computed(() => {
  const g = {}
  for (const p of rows.value) (g[p.dept] ||= []).push(p)

  return g
})

const current = computed(() => people.find(p => p.id === preview.value))
</script>

<template>
  <div>
    <LabBanner
      name="Personnes"
      compare="/company/people"
    />

    <!-- Header -->
    <div class="d-flex flex-wrap align-center gap-3 mb-5">
      <div class="flex-grow-1">
        <h1 class="text-h3 font-weight-bold lz-title">
          Personnes <span class="text-medium-emphasis font-weight-regular">· {{ people.length }}</span>
        </h1>
        <div class="text-body-1 text-medium-emphasis">
          Votre équipe, ses accès et ce qu'il reste à faire pour chacun.
        </div>
      </div>
      <VBtn
        variant="text"
        prepend-icon="tabler-file-export"
      >
        Exporter
      </VBtn>
      <VBtn
        color="primary"
        prepend-icon="tabler-user-plus"
        append-icon="tabler-chevron-down"
      >
        Ajouter une personne
      </VBtn>
    </div>

    <!-- Summary -->
    <div class="lz-summary mb-5">
      <div
        v-for="s in [
          { icon: 'tabler-users', color: 'primary', value: 8, label: 'Effectif', hint: '+2 ce mois' },
          { icon: 'tabler-key', color: 'success', value: 4, label: 'Accès actifs', hint: '2 sans accès' },
          { icon: 'tabler-rocket', color: 'info', value: 1, label: 'En intégration', hint: 'démarre lundi' },
          { icon: 'tabler-file-alert', color: 'error', value: 3, label: 'Documents manquants', hint: '1 bloquant' },
        ]"
        :key="s.label"
        class="lz-summary__item"
      >
        <VAvatar
          :color="s.color"
          variant="tonal"
          rounded="lg"
        >
          <VIcon :icon="s.icon" />
        </VAvatar>
        <div>
          <div class="lz-summary__value">
            {{ s.value }}
          </div>
          <div class="text-body-2">
            {{ s.label }} <span class="text-medium-emphasis">· {{ s.hint }}</span>
          </div>
        </div>
      </div>
    </div>

    <VCard class="lzr-card lzr-card--flat">
      <!-- Toolbar: segments, search, view -->
      <div class="lz-toolbar">
        <div class="lz-chips">
          <button
            v-for="s in segments"
            :key="s.key"
            type="button"
            class="lz-chip"
            :class="{ 'lz-chip--on': segment === s.key }"
            @click="segment = s.key"
          >
            {{ s.label }}
            <span
              class="lz-chip__n"
              :class="s.tone && segment !== s.key ? `text-${s.tone}` : ''"
            >{{ s.n }}</span>
          </button>
        </div>
        <VSpacer />
        <AppTextField
          v-model="q"
          placeholder="Nom, poste, service…"
          prepend-inner-icon="tabler-search"
          density="compact"
          style="max-inline-size: 260px;"
          hide-details
        />
        <div class="lz-seg">
          <button
            v-for="v in [['list', 'tabler-list'], ['cards', 'tabler-layout-grid'], ['org', 'tabler-sitemap']]"
            :key="v[0]"
            type="button"
            class="lz-seg__btn"
            :class="{ 'lz-seg__btn--on': view === v[0] }"
            :aria-label="v[0]"
            @click="view = v[0]"
          >
            <VIcon
              :icon="v[1]"
              size="18"
            />
          </button>
        </div>
      </div>

      <!-- LIST -->
      <div
        v-if="view === 'list'"
        class="lz-list"
      >
        <div class="lz-row lz-row--head">
          <span>Personne</span><span>Service</span><span>Accès</span><span>Fiche</span><span>Prochaine action</span><span />
        </div>
        <TransitionGroup name="lz-list">
          <div
            v-for="p in rows"
            :key="p.id"
            class="lz-row"
            :class="{ 'lz-row--on': preview === p.id }"
            @click="preview = p.id"
          >
            <div class="d-flex align-center gap-3 min-w-0">
              <VBadge
                dot
                :model-value="!!p.presence"
                :color="p.presence || 'success'"
                location="bottom end"
                offset-x="3"
                offset-y="3"
              >
                <VAvatar
                  color="primary"
                  variant="tonal"
                  size="38"
                >
                  <span class="text-body-2 font-weight-bold">{{ p.i }}</span>
                </VAvatar>
              </VBadge>
              <div class="min-w-0">
                <div class="font-weight-medium text-truncate">
                  {{ p.name }}
                </div>
                <div class="text-caption text-medium-emphasis text-truncate">
                  {{ p.position }} · {{ p.contract }}
                </div>
              </div>
            </div>
            <span class="text-body-2">{{ p.dept }}</span>
            <div>
              <VChip
                size="small"
                :color="accessPill(p.access).color"
                variant="tonal"
                label
              >
                {{ accessPill(p.access).text }}
              </VChip>
              <div
                v-if="p.role"
                class="text-caption text-medium-emphasis mt-1"
              >
                {{ p.role }}
              </div>
            </div>
            <div class="d-flex align-center gap-2">
              <VProgressCircular
                :model-value="p.complete"
                :color="p.complete === 100 ? 'success' : p.complete >= 70 ? 'warning' : 'error'"
                size="30"
                width="3"
              >
                <span class="lz-ring-txt">{{ p.complete }}</span>
              </VProgressCircular>
            </div>
            <div>
              <VBtn
                v-if="p.next"
                size="small"
                variant="tonal"
                :color="p.next.tone"
                :prepend-icon="p.next.icon"
                @click.stop
              >
                {{ p.next.label }}
              </VBtn>
              <span
                v-else
                class="text-caption text-success d-inline-flex align-center gap-1"
              ><VIcon
                icon="tabler-circle-check"
                size="16"
              />À jour</span>
            </div>
            <VBtn
              icon="tabler-dots-vertical"
              variant="text"
              size="small"
              @click.stop
            />
          </div>
        </TransitionGroup>
      </div>

      <!-- CARDS grouped by service -->
      <div
        v-else-if="view === 'cards'"
        class="pa-5"
      >
        <div
          v-for="(list, dept) in groups"
          :key="dept"
          class="mb-6"
        >
          <div class="text-overline text-medium-emphasis mb-2">
            {{ dept }} · {{ list.length }}
          </div>
          <div class="lz-cards">
            <VCard
              v-for="p in list"
              :key="p.id"
              class="lzr-card lzr-card--raised pa-4 text-center"
              @click="preview = p.id"
            >
              <VAvatar
                size="56"
                color="primary"
                variant="tonal"
                class="mb-2"
              >
                <span class="text-h6">{{ p.i }}</span>
              </VAvatar>
              <div class="font-weight-medium">
                {{ p.name }}
              </div>
              <div class="text-caption text-medium-emphasis mb-2">
                {{ p.position }}
              </div>
              <VChip
                size="x-small"
                :color="accessPill(p.access).color"
                variant="tonal"
                label
              >
                {{ accessPill(p.access).text }}
              </VChip>
            </VCard>
          </div>
        </div>
      </div>

      <!-- ORG (proposal) -->
      <div
        v-else
        class="pa-8 text-center"
      >
        <VIcon
          icon="tabler-sitemap"
          size="40"
          color="primary"
        />
        <div class="text-h6 mt-2">
          Organigramme — proposé
        </div>
        <p class="text-body-2 text-medium-emphasis mx-auto mb-0 lz-measure">
          Services et postes existent déjà (Organisation). Une vue organigramme les rendrait lisibles
          sans nouvelle donnée. Elle n'est pas prototypée ici.
        </p>
      </div>
    </VCard>

    <!-- Quick view drawer -->
    <VNavigationDrawer
      :model-value="!!current"
      location="end"
      temporary
      width="420"
      class="lz-drawer"
      @update:model-value="v => { if (!v) preview = null }"
    >
      <template v-if="current">
        <div class="lz-drawer__hero">
          <VBtn
            icon="tabler-x"
            variant="text"
            size="small"
            class="lz-drawer__close"
            @click="preview = null"
          />
          <div class="lz-drawer__avatar">
            {{ current.i }}
          </div>
          <div class="text-h5 font-weight-bold mt-3">
            {{ current.name }}
          </div>
          <div class="lz-drawer__sub">
            {{ current.position }} · {{ current.dept }} · {{ current.contract }}
          </div>
        </div>
        <div class="pa-5 d-flex flex-column gap-5">
          <div class="d-flex gap-2">
            <VBtn
              color="primary"
              class="flex-grow-1"
              prepend-icon="tabler-user"
            >
              Ouvrir la fiche
            </VBtn>
            <VBtn
              variant="tonal"
              icon="tabler-mail"
            />
            <VBtn
              variant="tonal"
              icon="tabler-calendar"
            />
          </div>
          <div>
            <div class="d-flex justify-space-between mb-2">
              <span class="font-weight-medium">Fiche complète à {{ current.complete }} %</span>
              <span class="text-caption text-medium-emphasis">exigé par le poste</span>
            </div>
            <VProgressLinear
              :model-value="current.complete"
              color="success"
              rounded
              height="8"
              class="mb-3"
            />
            <div
              v-for="c in [['Identité et coordonnées', true], ['Contrat et rémunération', true], ['Permis C1 — expire le 12/12', false], ['Visite médicale', false]]"
              :key="c[0]"
              class="d-flex align-center gap-2 py-1 text-body-2"
            >
              <VIcon
                :icon="c[1] ? 'tabler-circle-check' : 'tabler-circle-dashed'"
                :color="c[1] ? 'success' : 'warning'"
                size="18"
              />{{ c[0] }}
              <VSpacer />
              <VBtn
                v-if="!c[1]"
                size="x-small"
                variant="text"
                color="primary"
              >
                Demander
              </VBtn>
            </div>
          </div>
          <div>
            <div class="font-weight-medium mb-2">
              Accès à Leezr
            </div>
            <div class="lz-kv">
              <span>État</span><VChip
                size="small"
                :color="accessPill(current.access).color"
                variant="tonal"
                label
              >
                {{ accessPill(current.access).text }}
              </VChip>
              <span>Rôle</span><strong>{{ current.role || '—' }}</strong>
              <span>Dernière connexion</span><strong>aujourd'hui, 07:12</strong>
            </div>
          </div>
          <div>
            <div class="font-weight-medium mb-2">
              Cette semaine
            </div>
            <div class="lz-kv">
              <span>Heures pointées</span><strong>28 h 30</strong>
              <span>Planifiées</span><strong>35 h</strong>
              <span>Congés à venir</span><strong>27–28 nov.</strong>
            </div>
          </div>
        </div>
      </template>
    </VNavigationDrawer>
  </div>
</template>

<style scoped>
.lz-title { letter-spacing: -.03em; }
.lz-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 1rem; }
.lz-summary__item { display: flex; align-items: center; gap: .875rem; padding: 1rem 1.125rem; border-radius: 14px; background: rgb(var(--v-theme-surface)); border: 1px solid rgba(var(--v-border-color), .1); }
.lz-summary__value { font-size: 1.5rem; font-weight: 700; letter-spacing: -.02em; line-height: 1.1; }
.lz-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem; padding: 1rem 1.25rem; border-block-end: 1px solid rgba(var(--v-border-color), .1); }
.lz-chips { display: flex; flex-wrap: wrap; gap: .375rem; }
.lz-chip { display: inline-flex; align-items: center; gap: .375rem; padding: .375rem .75rem; border-radius: 999px; font-size: .8125rem; font-weight: 500; color: rgba(var(--v-theme-on-surface), .75); background: rgba(var(--v-theme-on-surface), .05); transition: background .18s, color .18s; }
.lz-chip:hover { background: rgba(var(--v-theme-on-surface), .09); }
.lz-chip--on { background: rgb(var(--v-theme-primary)); color: #fff; }
.lz-chip__n { font-weight: 700; }
.lz-seg { display: inline-flex; padding: 3px; border-radius: 10px; background: rgba(var(--v-theme-on-surface), .06); }
.lz-seg__btn { padding: .3rem .55rem; border-radius: 8px; color: rgba(var(--v-theme-on-surface), .6); display: inline-flex; }
.lz-seg__btn--on { background: rgb(var(--v-theme-surface)); color: rgb(var(--v-theme-on-surface)); box-shadow: 0 1px 3px rgba(0, 0, 0, .12); }
.lz-row { display: grid; grid-template-columns: minmax(220px, 2.2fr) 1fr 1.1fr .6fr 1.7fr 40px; align-items: center; gap: 1rem; padding: .75rem 1.25rem; border-block-end: 1px solid rgba(var(--v-border-color), .08); cursor: pointer; transition: background .15s; }
.lz-row:hover, .lz-row--on { background: rgba(var(--v-theme-primary), .04); }
.lz-row--on { box-shadow: inset 3px 0 0 rgb(var(--v-theme-primary)); }
.lz-row--head { cursor: default; font-size: .75rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: rgba(var(--v-theme-on-surface), .55); padding-block: .625rem; background: rgba(var(--v-theme-on-surface), .02); }
.lz-row--head:hover { background: rgba(var(--v-theme-on-surface), .02); }
.lz-ring-txt { font-size: .625rem; font-weight: 700; }
.lz-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 1rem; }
.lz-measure { max-inline-size: 52ch; }
.lz-drawer__hero { position: relative; padding: 2rem 1.5rem 1.5rem; color: #fff; background: linear-gradient(135deg, rgb(var(--v-theme-primary)), color-mix(in srgb, rgb(var(--v-theme-primary)) 60%, #0b1530)); }
.lz-drawer__avatar { inline-size: 64px; block-size: 64px; border-radius: 50%; background: #fff; color: rgb(var(--v-theme-primary)); display: grid; place-items: center; font-size: 1.375rem; font-weight: 700; }
.lz-drawer__sub { color: rgba(255, 255, 255, .82); font-size: .875rem; }
.lz-drawer__close { position: absolute; inset-block-start: .75rem; inset-inline-end: .75rem; color: #fff; }
.lz-kv { display: grid; grid-template-columns: auto 1fr; gap: .5rem 1rem; align-items: center; font-size: .875rem; }
.lz-kv > span { color: rgba(var(--v-theme-on-surface), .6); }
.lz-kv > strong, .lz-kv > .v-chip { justify-self: end; }
.lz-list-enter-active, .lz-list-leave-active { transition: all .2s ease; }
.lz-list-enter-from, .lz-list-leave-to { opacity: 0; transform: translateY(-4px); }
@media (max-width: 960px) {
  .lz-row { grid-template-columns: 1fr auto; }
  .lz-row > :nth-child(2), .lz-row > :nth-child(4), .lz-row > :nth-child(6) { display: none; }
  .lz-row > :nth-child(3) { display: none; }
  .lz-row--head { display: none; }
}
</style>
