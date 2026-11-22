<script setup>
import AppPageHeader from '@/components/app/AppPageHeader.vue'
// ADR-817 (L1) — fiche d'un POSTE MÉTIER : ce que fait la personne et ce qui est attendu d'elle.
// Les exigences viennent du registre des exigences (éditeur partagé) ; la « reprise » montre ce que les
// RÔLES D'ACCÈS imposent aujourd'hui aux personnes de ce poste, à adopter explicitement — jamais automatiquement.
// Aucun détail RBAC n'est éditable ici : les rôles n'apparaissent que comme ORIGINE d'une règle historique.
import { $api } from '@/utils/api'
import { useAppToast } from '@/composables/useAppToast'
import RequirementsEditor from '../../../requirements/_RequirementsEditor.vue'

// ADR-820 — onglet dans le chemin (/positions/{id}/{fields|documents|people|history}), nom historique conservé.
definePage({ name: 'company-workforce-organization-positions-id', meta: { module: 'workforce', permission: 'workforce.view' } })

const { t, te } = useI18n()
const route = useRoute()
const router = useRouter()
const { can } = useCan()
const { toast } = useAppToast()
const docLabel = (code, fallback) => (te(`documents.type.${code}`) ? t(`documents.type.${code}`) : fallback)

const id = computed(() => Number(route.params.id))
const position = ref(null)
const notFound = ref(false)
const askedTab = route.params.tab || route.query.tab
const tab = ref(askedTab === 'requirements' ? 'documents' : (askedTab || 'overview')) // ADR-819 : ancien onglet « Exigences »
watch(tab, v => {
  const { tab: _, ...query } = route.query
  router.replace({ name: 'company-workforce-organization-positions-id', params: { id: route.params.id, tab: v === 'overview' ? undefined : v }, query })
}, { immediate: !!route.query.tab || route.params.tab === 'requirements' })
const canEditInfo = computed(() => can('workforce.manage'))
const canConfigure = computed(() => can('settings.manage') || can('documents.configure'))

// ── Informations ──
const departments = ref([])
const editing = ref(false)
const form = ref({})
const savingInfo = ref(false)
const loadPosition = async () => {
  const list = await $api('/workforce/job-roles').catch(() => [])
  // Sans droit rémunération, la liste arrive en objet (clé `compensation_hidden`) : ne garder que les postes.
  const rows = Array.isArray(list) ? list : Object.values(list || {}).filter(r => r && typeof r === 'object' && r.id)
  position.value = rows.find(r => r.id === id.value) || null
  notFound.value = !position.value
}
const startEdit = async () => {
  form.value = { title: position.value.title, department_id: position.value.department_id || null, level: position.value.level || '', description: position.value.description || '' }
  if (!departments.value.length) {
    const d = await $api('/workforce/departments')
    departments.value = (Array.isArray(d) ? d : (d.data || [])).map(x => ({ title: x.name, value: x.id }))
  }
  editing.value = true
}
// ADR-861 : activities exercised in the position (scope the fields a module asks for them — e.g. the Fleet licence)
const ACTIVITIES = ['driving', 'dispatching', 'management']
const activities = computed(() => position.value?.metadata?.activities || [])
const savingActivity = ref(null)
watchEffect(() => { if (position.value) document.title = `${position.value.title} · ${t('positions.back')} · Leezr` })
const toggleActivity = async (key, on) => {
  savingActivity.value = key
  try {
    const next = on ? [...new Set([...activities.value, key])] : activities.value.filter(a => a !== key)
    await $api(`/workforce/job-roles/${id.value}`, { method: 'PUT', body: { activities: next } })
    toast(t('l7.activity.saved'), 'success')
    await loadPosition()
  }
  catch (e) { toast(e?.data?.message || t('common.error'), 'error') }
  finally { savingActivity.value = null }
}
const saveInfo = async () => {
  savingInfo.value = true
  try {
    await $api(`/workforce/job-roles/${id.value}`, { method: 'PUT', body: form.value })
    toast(t('positions.saved'), 'success')
    editing.value = false
    await loadPosition()
  }
  catch (e) { toast(e?.data?.message || t('common.error'), 'error') }
  finally { savingInfo.value = false }
}

// ── Collaborateurs du poste (périmètre de l'utilisateur, appliqué par le serveur) + état de leur dossier ──
// ADR-819 : le calcul EFFECTIF (rôle ou poste) vient du serveur ; appliquer les exigences aux personnes
// en place passe par un aperçu puis une confirmation — jamais d'office.
const people = ref([])
const peopleLoaded = ref(false)
const application = ref(null)
const canDocs = computed(() => can('documents.view'))
const loadPeople = async () => {
  if (canDocs.value) {
    application.value = await $api(`/workforce/job-roles/${id.value}/people-documents`).catch(() => null)
    people.value = application.value?.people || []
  }
  else {
    const res = await $api('/workforce/employees', { query: { job_role_id: id.value, per_page: 100 } })
    people.value = (res.data || res || []).map(p => ({ id: p.id, name: `${p.first_name} ${p.last_name}`, status: p.status, account: !!p.user_id }))
  }
  peopleLoaded.value = true
}
const docName = code => docLabel(code, code)
const applyOpen = ref(false)
const applying = ref(false)
const applyTargets = computed(() => people.value.filter(p => p.to_request?.length && !p.preparatory && p.status !== 'terminated'))
const applyRequirements = async () => {
  applying.value = true
  try {
    const res = await $api(`/workforce/job-roles/${id.value}/apply-requirements`, { method: 'POST', body: { employee_ids: applyTargets.value.map(p => p.id) } })
    const n = Object.values(res.created || {}).reduce((a, c) => a + c.length, 0)
    toast(t('positions.apply.done', { n }), 'success')
    applyOpen.value = false
    await loadPeople()
  }
  catch (e) { toast(e?.data?.message || Object.values(e?.data?.errors || {})[0]?.[0] || t('common.error'), 'error') }
  finally { applying.value = false }
}
const authorityIsPosition = computed(() => application.value?.authority === 'position')

// ── Reprise des règles historiques (lecture seule, adoption explicite) ──
const proposal = ref(null)
const selected = ref([])
const adoptPreview = ref(null)
const adopting = ref(false)
const editorKey = ref(0)
const loadProposal = async () => {
  proposal.value = await $api('/workforce/requirements/proposal', { query: { scope: `job_role:${id.value}` } })
  selected.value = []
}
const selectable = item => ['new', 'partial'].includes(item.status)
const itemLabel = item => (item.kind === 'document' ? docLabel(item.code, item.label) : item.label)
const ruleText = o => t(`positions.reprise.rule.${o.rule}`, { role: o.role, n: o.people })
const adoptItems = () => selected.value.map(key => { const [kind, code] = key.split(':'); return { kind, code, state: 'required' } })
const previewAdoption = async () => {
  try {
    adoptPreview.value = await $api('/workforce/requirements/preview', { method: 'POST', body: { scope: `job_role:${id.value}`, items: adoptItems() } })
  }
  catch (e) { toast(e?.data?.message || t('common.error'), 'error') }
}
const adopt = async () => {
  adopting.value = true
  try {
    await $api('/workforce/requirements', { method: 'POST', body: { scope: `job_role:${id.value}`, items: adoptItems() } })
    toast(t('positions.reprise.adopted', { n: selected.value.length }), 'success')
    adoptPreview.value = null
    await loadProposal()
    editorKey.value++
  }
  catch (e) { toast(e?.data?.message || t('common.error'), 'error') }
  finally { adopting.value = false }
}

// ADR-822 — aperçu des exigences EFFECTIVES du poste (lecture du registre, aucune déduction côté client).
const requirements = ref(null)
const loadRequirements = async () => {
  if (!canConfigure.value)
    return
  requirements.value = await $api('/workforce/requirements', { query: { scope: `job_role:${id.value}` } }).catch(() => null)
}
const reqList = kind => (requirements.value?.state?.[kind] || []).filter(r => r.state === 'required')
  .map(r => (kind === 'documents' ? docLabel(r.code, r.label) : r.label))
// ADR-824 — « Utilisation du poste » : personnes affectées + dossiers d'embauche ouverts sur ce poste (lecture
// existante filtrée par poste, réservée à workforce.manage comme l'espace Embauches — jamais pour les autres).
const canHiring = computed(() => can('workforce.manage'))
const hiringFiles = ref([])
const hiringLoaded = ref(false)
const loadHiring = async () => {
  if (!canHiring.value)
    return
  hiringFiles.value = (await $api('/workforce/onboarding-files', { query: { job_role_id: id.value } }).catch(() => ({ invitations: [] }))).invitations || []
  hiringLoaded.value = true
}
const openHiring = computed(() => hiringFiles.value.filter(f => !['done', 'abandoned'].includes(f.bucket)))
const BUCKET_COLOR = { waiting_company: 'secondary', waiting_candidate: 'info', to_verify: 'warning', to_finalize: 'primary', done: 'success', abandoned: 'error' }
onMounted(() => { loadRequirements(); loadHiring(); if (canDocs.value && !peopleLoaded.value) loadPeople() })

watch(tab, v => {
  if (['people', 'fields', 'documents'].includes(v) && !peopleLoaded.value)
    loadPeople()
  if (v === 'reprise' && !proposal.value)
    loadProposal()
}, { immediate: true })
onMounted(loadPosition)

const STATUS_TONE = { new: 'primary', partial: 'warning', configured: 'success', unavailable: 'secondary' }
</script>

<template>
  <div>
    <VAlert
      v-if="notFound"
      type="warning"
      variant="tonal"
      :title="t('positions.notFoundTitle')"
      :text="t('positions.notFoundMessage')"
      data-test="position-not-found"
    />

    <template v-else-if="position">
      <!-- ADR-861 (L7.8) : shared page header — way back, chips under the title, edit as the page action -->
      <AppPageHeader
        :back="{ to: '/company/workforce/organization/positions', label: t('positions.back') }"
        :title="position.title"
        :description="t('positions.subtitle')"
        data-test="position-header"
      >
        <template #meta>
          <VChip
            v-if="position.department?.name"
            size="small"
            label
            color="info"
            variant="tonal"
            prepend-icon="tabler-sitemap"
          >
            {{ position.department.name }}
          </VChip>
          <VChip
            v-if="position.level"
            size="small"
            label
            variant="tonal"
          >
            {{ position.level }}
          </VChip>
          <VChip
            size="small"
            label
            variant="tonal"
            prepend-icon="tabler-users"
          >
            {{ t('positions.peopleCount', { n: position.employees_count ?? 0 }) }}
          </VChip>
          <VChip
            v-for="a in activities"
            :key="a"
            size="small"
            label
            color="primary"
            variant="tonal"
            prepend-icon="tabler-steering-wheel"
          >
            {{ t(`l7.activity.${a}`) }}
          </VChip>
        </template>
        <template
          v-if="canEditInfo && tab === 'overview' && !editing"
          #actions
        >
          <VBtn
            variant="tonal"
            prepend-icon="tabler-edit"
            data-test="position-edit"
            @click="startEdit"
          >
            {{ t('common.edit') }}
          </VBtn>
        </template>
      </AppPageHeader>

      <VTabs
        v-model="tab"
        class="mb-4"
      >
        <VTab
          value="overview"
          data-test="position-tab-overview"
        >
          {{ t('positions.tabs.overview') }}
        </VTab>
        <VTab
          v-if="canConfigure"
          value="fields"
          data-test="position-tab-fields"
        >
          {{ t('positions.tabs.fields') }}
        </VTab>
        <VTab
          v-if="canConfigure"
          value="documents"
          data-test="position-tab-documents"
        >
          {{ t('positions.tabs.documents') }}
        </VTab>
        <VTab
          value="people"
          data-test="position-tab-people"
        >
          {{ t('positions.tabs.people', { n: position.employees_count ?? 0 }) }}
        </VTab>
        <VTab
          v-if="canConfigure"
          value="reprise"
          data-test="position-tab-reprise"
        >
          {{ t('positions.tabs.reprise') }}
        </VTab>
      </VTabs>

      <VWindow v-model="tab">
        <!-- Informations -->
        <VWindowItem value="overview">
          <VRow v-if="!editing">
            <VCol
              cols="12"
              md="6"
            >
              <VCard
                :title="t('positions.fields.description')"
                class="h-100"
              >
                <VCardText class="text-body-1">
                  <span
                    v-if="position.description"
                    style="white-space: pre-line;"
                  >{{ position.description }}</span>
                  <span
                    v-else
                    class="text-disabled"
                  >{{ t('positions.noDescription') }}</span>
                </VCardText>
                <VDivider />
                <VCardText data-test="position-activities">
                  <div class="text-body-2 font-weight-medium mb-1">
                    {{ t('l7.activity.title') }}
                  </div>
                  <p class="text-body-2 text-medium-emphasis mb-2">
                    {{ t('l7.activity.hint') }}
                  </p>
                  <template v-if="canEditInfo">
                    <VSwitch
                      v-for="a in ACTIVITIES"
                      :key="a"
                      :model-value="activities.includes(a)"
                      :label="t(`l7.activity.${a}`)"
                      :loading="savingActivity === a"
                      :disabled="!!savingActivity"
                      density="compact"
                      hide-details
                      :data-test="`position-activity-${a}`"
                      @update:model-value="v => toggleActivity(a, v)"
                    />
                  </template>
                  <div
                    v-else
                    class="d-flex flex-wrap gap-2"
                  >
                    <VChip
                      v-for="a in activities"
                      :key="a"
                      size="small"
                      color="primary"
                      variant="tonal"
                    >
                      {{ t(`l7.activity.${a}`) }}
                    </VChip>
                    <span
                      v-if="!activities.length"
                      class="text-disabled text-body-2"
                    >{{ t('l7.activity.none') }}</span>
                  </div>
                </VCardText>
              </VCard>
            </VCol>
            <VCol
              v-if="canConfigure"
              cols="12"
              md="6"
            >
              <VCard
                :title="t('positions.effective.title')"
                :subtitle="t('positions.effective.subtitle')"
                class="h-100"
                data-test="position-effective"
              >
                <VCardText>
                  <VAlert
                    v-if="application && !authorityIsPosition"
                    type="info"
                    variant="tonal"
                    density="compact"
                    class="mb-4"
                    data-test="position-authority-role"
                  >
                    {{ t('positions.effective.roleAuthority') }}
                  </VAlert>
                  <template
                    v-for="kind in ['fields', 'documents']"
                    :key="kind"
                  >
                    <div class="d-flex align-center justify-space-between mb-2">
                      <span class="text-body-2 font-weight-medium">{{ t(`positions.effective.${kind}`, { n: reqList(kind).length }) }}</span>
                      <VBtn
                        variant="text"
                        size="small"
                        :data-test="`position-effective-edit-${kind}`"
                        @click="tab = kind"
                      >
                        {{ t('positions.effective.configure') }}
                      </VBtn>
                    </div>
                    <div class="d-flex flex-wrap gap-2 mb-4">
                      <VChip
                        v-for="label in reqList(kind)"
                        :key="label"
                        size="small"
                        label
                        color="warning"
                        variant="tonal"
                      >
                        {{ label }}
                      </VChip>
                      <span
                        v-if="!reqList(kind).length"
                        class="text-body-2 text-disabled"
                      >{{ t('positions.effective.none') }}</span>
                    </div>
                  </template>
                </VCardText>
              </VCard>
            </VCol>
            <VCol cols="12">
              <VCard
                :title="t('positions.usage.title')"
                :subtitle="t('positions.usage.subtitle')"
                data-test="position-usage"
              >
                <VList class="pt-0">
                  <VListItem
                    prepend-icon="tabler-users"
                    :title="t('positions.usage.people', { n: position.employees_count ?? 0 })"
                    :subtitle="t('positions.usage.peopleHint')"
                    data-test="position-usage-people"
                    @click="tab = 'people'"
                  >
                    <template #append>
                      <VIcon
                        icon="tabler-chevron-right"
                        size="18"
                        class="text-disabled"
                      />
                    </template>
                  </VListItem>
                  <template v-if="canHiring">
                    <VDivider class="my-1" />
                    <VListSubheader>
                      {{ t('positions.usage.hiring', { n: openHiring.length }) }}
                    </VListSubheader>
                    <VListItem
                      v-for="f in openHiring"
                      :key="f.id"
                      :to="`/company/workforce/hiring/${f.id}`"
                      :title="f.name || t('positions.usage.unnamed')"
                      :subtitle="t('positions.usage.fileProgress', { fd: f.fields_filled, ft: f.fields_total, dd: f.documents_deposited, dt: f.documents_total })"
                      :data-test="`position-usage-file-${f.id}`"
                    >
                      <template #append>
                        <VChip
                          size="small"
                          label
                          :color="BUCKET_COLOR[f.bucket] || 'secondary'"
                        >
                          {{ t(`hiring.bucket.${f.bucket}`, f.bucket) }}
                        </VChip>
                      </template>
                    </VListItem>
                    <VListItem
                      v-if="hiringLoaded && !openHiring.length"
                      class="text-medium-emphasis"
                      :title="t('positions.usage.noHiring')"
                    />
                    <VListItem
                      prepend-icon="tabler-user-plus"
                      :title="t('positions.usage.openHiring')"
                      to="/company/workforce/hiring"
                      data-test="position-usage-hiring-link"
                    />
                  </template>
                </VList>
              </VCard>
            </VCol>
          </VRow>
          <VCard v-else>
            <VCardText>
              <VRow>
                <VCol
                  cols="12"
                  md="6"
                >
                  <AppTextField
                    v-model="form.title"
                    :label="t('positions.fields.title')"
                  />
                </VCol>
                <VCol
                  cols="12"
                  md="6"
                >
                  <AppSelect
                    v-model="form.department_id"
                    :items="departments"
                    :label="t('positions.fields.department')"
                    clearable
                  />
                </VCol>
                <VCol
                  cols="12"
                  md="6"
                >
                  <AppTextField
                    v-model="form.level"
                    :label="t('positions.fields.level')"
                  />
                </VCol>
                <VCol cols="12">
                  <AppTextarea
                    v-model="form.description"
                    :label="t('positions.fields.description')"
                    rows="3"
                  />
                </VCol>
              </VRow>
              <div class="d-flex gap-2 mt-2">
                <VBtn
                  :loading="savingInfo"
                  @click="saveInfo"
                >
                  {{ t('common.save') }}
                </VBtn>
                <VBtn
                  variant="tonal"
                  color="secondary"
                  @click="editing = false"
                >
                  {{ t('common.cancel') }}
                </VBtn>
              </div>
            </VCardText>
          </VCard>
        </VWindowItem>

        <!-- Champs / Documents : exigences du poste (registre des exigences), calcul effectif affiché -->
        <VWindowItem
          v-for="section in (canConfigure ? ['fields', 'documents'] : [])"
          :key="section"
          :value="section"
        >
          <VAlert
            :type="authorityIsPosition ? 'success' : 'info'"
            variant="tonal"
            density="compact"
            class="mb-4"
            data-test="position-requirements-status"
          >
            {{ authorityIsPosition ? t('positions.effective.position') : t('positions.effective.role') }}
          </VAlert>
          <RequirementsEditor
            :key="`${section}-${editorKey}`"
            :position-id="id"
            :only="section === 'fields' ? 'field' : 'document'"
          />
        </VWindowItem>

        <!-- Personnes -->
        <VWindowItem value="people">
          <VCard>
            <VCardText
              v-if="peopleLoaded && !people.length"
              class="text-medium-emphasis"
            >
              {{ t('positions.noPeople') }}
            </VCardText>
            <template v-else>
              <VCardText
                v-if="application?.to_request_total && can('documents.manage')"
                class="d-flex flex-wrap align-center gap-3 pb-0"
              >
                <span class="text-body-2 flex-grow-1">{{ t('positions.apply.summary', { n: application.to_request_total, p: applyTargets.length }) }}</span>
                <VBtn
                  variant="tonal"
                  prepend-icon="tabler-send"
                  data-test="position-apply-open"
                  @click="applyOpen = true"
                >
                  {{ t('positions.apply.action') }}
                </VBtn>
              </VCardText>
              <VList data-test="position-people">
                <VListItem
                  v-for="p in people"
                  :key="p.id"
                  :to="`/company/people/employee-${p.id}`"
                  :title="p.name"
                  :subtitle="[t(`positions.status.${p.preparatory ? 'preparatory' : p.status}`), p.account ? t('positions.withAccount') : t('positions.withoutAccount')].join(' · ')"
                >
                  <template
                    v-if="p.complete !== undefined"
                    #append
                  >
                    <VChip
                      size="small"
                      label
                      :color="p.complete ? 'success' : (p.to_request.length ? 'warning' : 'info')"
                      :data-test="`position-person-state-${p.id}`"
                    >
                      {{ p.complete ? t('positions.file.complete') : p.to_request.length ? t('positions.file.missing', { n: p.to_request.length }) : t('positions.file.requested', { n: p.requested.length }) }}
                    </VChip>
                  </template>
                </VListItem>
              </VList>
            </template>
            <VCardText class="text-caption text-medium-emphasis">
              {{ t('positions.peopleScope') }}
            </VCardText>
          </VCard>
        </VWindowItem>

        <!-- Reprise -->
        <VWindowItem
          v-if="canConfigure"
          value="reprise"
        >
          <VCard v-if="proposal">
            <VCardText>
              <p class="mb-2">
                {{ t('positions.reprise.intro') }}
              </p>
              <p
                class="text-body-2 text-medium-emphasis mb-4"
                data-test="position-reprise-people"
              >
                {{ t('positions.reprise.people', { n: proposal.people.total, a: proposal.people.accountless, r: proposal.people.without_role }) }}
              </p>

              <div
                v-if="!proposal.items.length"
                class="text-medium-emphasis"
                data-test="position-reprise-empty"
              >
                {{ t('positions.reprise.empty') }}
              </div>

              <div
                v-for="item in proposal.items"
                :key="`${item.kind}:${item.code}`"
                class="d-flex align-start gap-3 py-2 border-b"
                data-test="position-reprise-item"
              >
                <VCheckbox
                  v-model="selected"
                  :value="`${item.kind}:${item.code}`"
                  :disabled="!selectable(item)"
                  density="compact"
                  hide-details
                />
                <div class="flex-grow-1">
                  <div class="d-flex align-center gap-2 flex-wrap">
                    <span class="font-weight-medium">{{ itemLabel(item) }}</span>
                    <VChip
                      size="x-small"
                      variant="tonal"
                    >
                      {{ t(`positions.reprise.kind.${item.kind}`) }}
                    </VChip>
                    <VChip
                      size="x-small"
                      :color="STATUS_TONE[item.status]"
                      variant="tonal"
                    >
                      {{ t(`positions.reprise.status.${item.status}`) }}
                    </VChip>
                  </div>
                  <div class="text-body-2 text-medium-emphasis">
                    {{ item.origins.map(ruleText).join(' · ') }}
                  </div>
                  <div
                    v-if="item.not_from.length"
                    class="text-body-2 text-warning"
                  >
                    {{ t('positions.reprise.notFrom', { roles: item.not_from.map(r => `${r.role} (${r.people})`).join(', ') }) }}
                  </div>
                </div>
              </div>

              <VAlert
                v-if="proposal.hidden_by_roles.length || proposal.visibility_rules"
                type="warning"
                variant="tonal"
                density="compact"
                class="mt-4"
              >
                {{ t('positions.reprise.notCarried', { n: proposal.hidden_by_roles.length + proposal.visibility_rules }) }}
              </VAlert>

              <p class="text-caption text-medium-emphasis mt-4">
                {{ t('positions.reprise.consequences') }}
              </p>

              <div class="d-flex gap-2 mt-2">
                <VBtn
                  :disabled="!selected.length"
                  variant="tonal"
                  data-test="position-reprise-preview"
                  @click="previewAdoption"
                >
                  {{ t('positions.reprise.preview', { n: selected.length }) }}
                </VBtn>
              </div>

              <VAlert
                v-if="adoptPreview"
                type="info"
                variant="tonal"
                class="mt-4"
                data-test="position-reprise-preview-result"
              >
                {{ t('positions.reprise.previewResult', { n: adoptPreview.changes?.added?.length ?? 0, p: adoptPreview.people?.total ?? adoptPreview.people ?? 0 }) }}
                <div class="mt-3">
                  <VBtn
                    :loading="adopting"
                    data-test="position-reprise-adopt"
                    @click="adopt"
                  >
                    {{ t('positions.reprise.adopt') }}
                  </VBtn>
                </div>
              </VAlert>
            </VCardText>
          </VCard>
          <VProgressLinear
            v-else
            indeterminate
          />
        </VWindowItem>
      </VWindow>
    </template>
    <VProgressLinear
      v-else
      indeterminate
    />
    <!-- ADR-819 : application aux personnes en place — aperçu puis confirmation explicite -->
    <VDialog
      v-model="applyOpen"
      max-width="560"
    >
      <VCard :title="t('positions.apply.title')">
        <VCardText>
          <VAlert
            v-if="!application?.can_apply"
            type="warning"
            variant="tonal"
            density="compact"
            class="mb-4"
          >
            {{ t('positions.effective.role') }}
          </VAlert>
          <p class="text-body-2">
            {{ t('positions.apply.intro') }}
          </p>
          <VList density="compact">
            <VListItem
              v-for="p in applyTargets"
              :key="p.id"
              :title="p.name"
              :subtitle="p.to_request.map(docName).join(', ')"
            />
          </VList>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="text"
            @click="applyOpen = false"
          >
            {{ t('common.cancel') }}
          </VBtn>
          <VBtn
            color="primary"
            :disabled="!application?.can_apply || !applyTargets.length"
            :loading="applying"
            data-test="position-apply-confirm"
            @click="applyRequirements"
          >
            {{ t('positions.apply.confirm') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
