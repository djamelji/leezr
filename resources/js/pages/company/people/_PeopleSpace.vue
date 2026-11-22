<script setup>
// ADR-754/755 (P2+P3) — « Collaborateurs » : espace CENTRAL de gestion des personnes, rôles et
// accès. Onglets selon les FACETTES : « Personnes » (liste unifiée, members.view|workforce.view)
// et « Rôles & autorisations » (définitions RBAC, roles.view — RÉUTILISE /company/roles sans
// duplication). Un utilisateur roles.view-seul voit l'onglet Rôles sans la liste des personnes.
import { useRoute, useRouter } from 'vue-router'
import { usePeopleListStore } from '@/modules/company/people/people-list.store'
import { useCan } from '@/composables/useCan'
import RolesManager from '@/pages/company/roles.vue'
import PersonAddDrawer from './_PersonAddDrawer.vue'
import PeopleHrTools from './_PeopleHrTools.vue'
import PeopleDirectory from './_PeopleDirectory.vue'
import MemberHealth from './_MemberHealth.vue'
import RoleCapabilities from './_RoleCapabilities.vue'
import PendingAccessReview from './_PendingAccessReview.vue'
import { useModuleStore } from '@/core/stores/module'
import { useCompanySettingsStore } from '@/modules/company/settings/settings.store'
import { useAppToast } from '@/composables/useAppToast'

// ADR-767 : plus de `surface: 'structure'` — un manager/RH NON administratif détenant la facette RH
// (`workforce.view`) doit atteindre ses collaborateurs. L'accès reste décidé par permission (facettes)
// et le périmètre de données par le serveur (WorkforceAccess).
// ADR-820 — sous-composant (sans definePage) monté par les pages /company/people, /people/hr, /people/roles :
// l'onglet est dans le CHEMIN (ADR-475), plus dans `?tab=`.
const props = defineProps({ section: { type: String, default: 'people' } })

const { t } = useI18n()
const route = useRoute()
const accessRevision = ref(0) // ADR-809 : rafraîchit les vues après confirmation groupée
const router = useRouter()
const store = usePeopleListStore()
const { can, canModule } = useCan()
const settings = useCompanySettingsStore()
const { toast } = useAppToast()

// ── ADR-777 (UX2) — « Ajouter une personne » : un seul parcours (tiroir unique). L'invitation et la
// fiche salarié restent les mêmes endpoints ; l'utilisateur ne choisit plus entre deux concepts.
const addOpen = ref(false)
const addPreset = ref(null)
const canAdd = computed(() => can('members.invite') || can('workforce.manage'))
const canHire = computed(() => canModule('workforce') && can('workforce.manage'))
const openAdd = (preset = null) => { addPreset.value = preset; addOpen.value = true }

const onPersonAdded = ({ personRef, tab }) => {
  if (personRef) router.push({ path: tab ? `/company/people/${personRef}/${tab}` : `/company/people/${personRef}` })
  else reload()
}

const canPeople = computed(() => can('members.view') || can('workforce.view'))
const canRoles = computed(() => can('roles.view'))

// ADR-802 — « Gestion RH » = l'ancienne liste /company/workforce/employees (même garde : module workforce +
// workforce.view). « Champs du profil » = l'ancien tiroir de /company/members (members.manage).
const moduleStore = useModuleStore()
const canHr = computed(() => moduleStore.isActive('workforce') && can('workforce.view'))
const rolesView = ref('capabilities') // ADR-803.1
// ADR-820 — la vue vient du chemin ; repli selon les facettes si elle n'est pas permise.
const ROUTE = { people: 'company-people', hr: 'company-people-hr', roles: 'company-people-roles' }

const initialTab = (props.section === 'roles' && canRoles.value) ? 'roles'
  : (props.section === 'hr' && canHr.value) ? 'hr'
    : (canPeople.value ? 'people' : 'roles')

const tab = ref(initialTab)

watch(tab, v => {
  // Changer de vue repart d'une URL propre (les filtres de la vue Gestion RH ne fuient pas ailleurs).
  if (route.name !== ROUTE[v]) router.replace({ name: ROUTE[v] })
})
watch(() => props.section, v => { if (v && v !== tab.value) tab.value = v })

// ADR-858 (L5) — the « Personnes » view is the V22 directory (search, filters, synthesis, quick view) ; the old
// « Outils → Champs » drawer is retired : field settings live in Company › Catalogue (one interface per rule).
const directory = ref(null)
function reload() { directory.value?.reload() }
const summary = computed(() => store.summary)

const headline = computed(() => {
  const x = summary.value
  if (!x) return t('peopleList.subtitle')
  const parts = [t('l5.head.people', { n: x.total }, x.total), t('l5.head.active', { n: x.active }, x.active)]
  if (x.invitations) parts.push(t('l5.head.invitations', { n: x.invitations }, x.invitations))
  if (x.incomplete) parts.push(t('l5.head.incomplete', { n: x.incomplete }, x.incomplete))

  return parts.join(' · ')
})

const canConfigureFields = computed(() => can('settings.manage'))

onMounted(() => {
  // Deep-links des anciens raccourcis (tableau de bord, onboarding) : `?action=invite|create`.
  if (route.query.action === 'invite' && can('members.invite')) openAdd(true)
  if (route.query.action === 'create' && can('workforce.manage')) openAdd(false)
})
</script>

<template>
  <div>
    <SettingsReturnLink /> <!-- ADR-781 -->
    <!-- ═══ ADR-858 (L5) : en-tête V22 ═══ -->
    <header class="lz-phead mb-5">
      <div class="lz-phead__txt">
        <h4 class="lz-phead__title">
          {{ t('l5.title') }}
        </h4>
        <p
          class="lz-phead__sub"
          data-test="people-headline"
        >
          {{ tab === 'people' ? headline : t('peopleList.subtitle') }}
        </p>
      </div>
      <div class="d-flex align-center flex-wrap gap-2">
        <VBtn
          v-if="tab === 'people' && canConfigureFields"
          variant="text"
          prepend-icon="tabler-forms"
          to="/company/catalogs/fields"
          data-test="people-configure-fields"
        >
          {{ t('l5.configureFields') }}
        </VBtn>
        <VBtn
          v-if="(tab === 'people' || tab === 'hr') && canAdd"
          color="primary"
          prepend-icon="tabler-user-plus"
          :append-icon="canHire ? 'tabler-chevron-down' : undefined"
          data-test="people-add"
          @click="canHire ? null : openAdd()"
        >
          {{ t('people.add.title') }}
          <VMenu
            v-if="canHire"
            activator="parent"
            location="bottom end"
          >
            <VList
              max-width="360"
              lines="two"
            >
              <VListItem
                prepend-icon="tabler-user-check"
                :title="t('people.add.choiceHire')"
                :subtitle="t('people.add.choiceHireHint')"
                to="/company/workforce/hiring?new=1"
                data-test="people-hire"
              />
              <VListItem
                prepend-icon="tabler-user-plus"
                :title="t('people.add.choiceQuick')"
                :subtitle="t('people.add.choiceQuickHint')"
                data-test="people-add-quick"
                @click="openAdd"
              />
            </VList>
          </VMenu>
        </VBtn>
      </div>
    </header>
    <!-- ADR-818 : les embauches vivent dans l'espace « Embauches » (une seule liste, un seul assistant) -->
    <MemberHealth v-if="tab === 'people' && can('members.view')" />
    <VTabs
      v-model="tab"
      class="v-tabs-pill mb-5"
      data-test="people-tabs"
    >
      <VTab
        v-if="canPeople"
        value="people"
      >
        <VIcon
          icon="tabler-users"
          start
          size="18"
        />{{ t('peopleList.tabPeople') }}
      </VTab>
      <VTab
        v-if="canHr"
        value="hr"
        data-test="people-tab-hr"
      >
        <VIcon
          icon="tabler-table-options"
          start
          size="18"
        />{{ t('peopleList.tabHr') }}
      </VTab>
      <VTab
        v-if="canRoles"
        value="roles"
      >
        <VIcon
          icon="tabler-shield-lock"
          start
          size="18"
        />{{ t('peopleList.tabRoles') }}
      </VTab>
    </VTabs>
    <!-- ═══ PERSONNES (ADR-858 : annuaire V22) ═══ -->
    <PeopleDirectory
      v-if="tab === 'people' && canPeople"
      ref="directory"
    />
    <VCard
      v-else
      flat
      border
    >
      <VWindow
        v-model="tab"
        class="disable-tab-transition"
      >
        <!-- ═══ RÔLES & AUTORISATIONS (réutilise /company/roles) ═══ -->
        <VWindowItem value="hr">
          <VCardText>
            <PeopleHrTools v-if="tab === 'hr' && canHr" @create="openAdd(false)" />
          </VCardText>
        </VWindowItem>
        <VWindowItem value="roles">
          <!-- ADR-803.1 : vue simple « par capacité » (même moteur que l'assistant de module) ; « par rôle » = vue avancée -->
          <div
            v-if="tab === 'roles' && canRoles"
            class="pa-4 pb-0"
          >
            <!--
              ADR-806 : onglets « pill » (preset DemoTabsBasicPill) — le VBtnToggle Vuexy est réservé aux icônes
              (largeur forcée à 36 px) : les deux libellés se chevauchaient. 
            -->
            <VTabs
              v-model="rolesView"
              class="v-tabs-pill"
              density="compact"
              data-test="roles-view"
            >
              <VTab
                value="capabilities"
                data-test="roles-view-capabilities"
              >
                {{ t('moduleSetup.byCapability') }}
              </VTab>
              <VTab
                value="roles"
                data-test="roles-view-roles"
              >
                {{ t('moduleSetup.byRole') }}
              </VTab>
            </VTabs>
          </div>
          <!-- ADR-809 : revue groupée des accès en attente (une seule décision) -->
          <div
            v-if="tab === 'roles' && canRoles"
            class="px-4 pt-4"
          >
            <PendingAccessReview @confirmed="accessRevision++" />
          </div>
          <VCardText v-if="tab === 'roles' && canRoles && rolesView === 'capabilities'">
            <RoleCapabilities :key="`caps-${accessRevision}`" />
          </VCardText>
          <RolesManager
            v-if="tab === 'roles' && canRoles && rolesView === 'roles'"
            :key="`roles-${accessRevision}`"
            embedded
          />
        </VWindowItem>
      </VWindow>
    </VCard>

    <PersonAddDrawer
      v-if="canAdd"
      v-model="addOpen"
      :preset-access="addPreset"
      @created="onPersonAdded"
    />
  </div>
</template>

<style scoped>
/* ADR-858 (L5) — V22 page header : strong title, the real headcount sentence, actions aligned to the right */
.lz-phead { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 1rem 1.5rem; }
.lz-phead__txt { flex: 1 1 320px; min-inline-size: 0; }
.lz-phead__title { font-size: clamp(1.75rem, 2.4vw, 2.25rem); font-weight: 700; letter-spacing: -.03em; line-height: 1.1; margin: 0; color: rgba(var(--v-theme-on-surface), var(--v-high-emphasis-opacity)); }
.lz-phead__sub { margin: .375rem 0 0; font-size: 1rem; color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity)); }
</style>
