<script setup>
// ADR-817 (L1) — fiche d'un POSTE MÉTIER : ce que fait la personne et ce qui est attendu d'elle.
// Les exigences viennent du registre des exigences (éditeur partagé) ; la « reprise » montre ce que les
// RÔLES D'ACCÈS imposent aujourd'hui aux personnes de ce poste, à adopter explicitement — jamais automatiquement.
// Aucun détail RBAC n'est éditable ici : les rôles n'apparaissent que comme ORIGINE d'une règle historique.
import { $api } from '@/utils/api'
import { useAppToast } from '@/composables/useAppToast'
import RequirementsEditor from '../../requirements/_RequirementsEditor.vue'

definePage({ meta: { module: 'workforce', permission: 'workforce.view' } })

const { t, te } = useI18n()
const route = useRoute()
const { can } = useCan()
const { toast } = useAppToast()
const docLabel = (code, fallback) => (te(`documents.type.${code}`) ? t(`documents.type.${code}`) : fallback)

const id = computed(() => Number(route.params.id))
const position = ref(null)
const notFound = ref(false)
const tab = ref(route.query.tab || 'overview')
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

// ── Personnes affectées (périmètre de l'utilisateur, appliqué par le serveur) ──
const people = ref([])
const peopleLoaded = ref(false)
const loadPeople = async () => {
  const res = await $api('/workforce/employees', { query: { job_role_id: id.value, per_page: 100 } })
  people.value = res.data || res || []
  peopleLoaded.value = true
}

// ── Reprise des règles historiques (lecture seule, adoption explicite) ──
const proposal = ref(null)
const selected = ref([])
const adoptPreview = ref(null)
const adopting = ref(false)
const editorRef = ref(null)
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
    editorRef.value?.load()
  }
  catch (e) { toast(e?.data?.message || t('common.error'), 'error') }
  finally { adopting.value = false }
}

watch(tab, v => {
  if (v === 'people' && !peopleLoaded.value)
    loadPeople()
  if (v === 'reprise' && !proposal.value)
    loadProposal()
}, { immediate: true })
onMounted(loadPosition)

const STATUS_TONE = { new: 'primary', partial: 'warning', configured: 'success', unavailable: 'secondary' }
</script>

<template>
  <div>
    <RouterLink
      to="/company/workforce/organization/positions"
      class="text-body-2 d-inline-flex align-center gap-1 mb-3"
    >
      <VIcon
        icon="tabler-arrow-left"
        size="16"
      />{{ t('positions.back') }}
    </RouterLink>

    <VAlert
      v-if="notFound"
      type="warning"
      variant="tonal"
      :title="t('positions.notFoundTitle')"
      :text="t('positions.notFoundMessage')"
      data-test="position-not-found"
    />

    <template v-else-if="position">
      <AppPageHeader
        :title="position.title"
        :description="t('positions.subtitle')"
      />

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
          value="requirements"
          data-test="position-tab-requirements"
        >
          {{ t('positions.tabs.requirements') }}
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
          <VCard>
            <VCardText v-if="!editing">
              <div class="d-flex flex-column gap-3">
                <div><span class="text-medium-emphasis">{{ t('positions.fields.title') }} :</span> {{ position.title }}</div>
                <div><span class="text-medium-emphasis">{{ t('positions.fields.department') }} :</span> {{ position.department?.name || '—' }}</div>
                <div><span class="text-medium-emphasis">{{ t('positions.fields.level') }} :</span> {{ position.level || '—' }}</div>
                <div><span class="text-medium-emphasis">{{ t('positions.fields.description') }} :</span> {{ position.description || '—' }}</div>
              </div>
              <VBtn
                v-if="canEditInfo"
                class="mt-4"
                variant="tonal"
                data-test="position-edit"
                @click="startEdit"
              >
                {{ t('common.edit') }}
              </VBtn>
            </VCardText>
            <VCardText v-else>
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

        <!-- Exigences -->
        <VWindowItem
          v-if="canConfigure"
          value="requirements"
        >
          <VAlert
            type="info"
            variant="tonal"
            density="compact"
            class="mb-4"
            data-test="position-requirements-status"
          >
            {{ t('positions.requirementsStatus') }}
          </VAlert>
          <RequirementsEditor
            ref="editorRef"
            :position-id="id"
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
            <VList
              v-else
              data-test="position-people"
            >
              <VListItem
                v-for="p in people"
                :key="p.id"
                :to="`/company/people/employee-${p.id}`"
                :title="`${p.first_name} ${p.last_name}`"
                :subtitle="p.user_id ? t('positions.withAccount') : t('positions.withoutAccount')"
              />
            </VList>
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
  </div>
</template>
