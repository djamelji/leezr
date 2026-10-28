<script setup>
// ADR-638 (W-E2) — éditeur visuel d'un preset organisationnel : arborescence de départements + postes,
// sans JSON brut. Contrôles de déplacement explicites (fiables), validation serveur au save/publish.
import { $platformApi } from '@/utils/platformApi'
import { useAppToast } from '@/composables/useAppToast'

definePage({ meta: { layout: 'platform', platform: true, module: 'platform.jobdomains', permission: 'manage_jobdomains' } })

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const { toast } = useAppToast()

const preset = ref(null)
const depts = ref([])
const roles = ref([])
const dirty = ref(false)
const saving = ref(false)
const deptDialog = ref(false)
const roleDialog = ref(false)
const deptForm = ref({ key: null, name: '', parent_key: null })
const roleForm = ref({ key: null, title: '', department_key: null, level: '' })

const readOnly = computed(() => preset.value?.status !== 'draft')

const load = async () => {
  preset.value = await $platformApi(`/organization-presets/${route.params.id}`)
  depts.value = (preset.value.structure?.departments || []).map(d => ({ ...d }))
  roles.value = (preset.value.structure?.job_roles || []).map(r => ({ ...r }))
  dirty.value = false
}

const slug = s => (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '').slice(0, 40) || 'item'
const uniqueKey = (base, existing) => { let k = base; let i = 1; while (existing.includes(k)) k = `${base}_${++i}`; return k }

// Depth for indentation.
const depthOf = key => {
  let d = 0; let cur = depts.value.find(x => x.key === key)
  const seen = new Set()
  while (cur?.parent_key && !seen.has(cur.key)) { seen.add(cur.key); d++; cur = depts.value.find(x => x.key === cur.parent_key) }

  return d
}
const deptItems = computed(() => depts.value.map(d => ({ title: d.name, value: d.key })))

const openAddDept = () => { deptForm.value = { key: null, name: '', parent_key: null }; deptDialog.value = true }
const openEditDept = d => { deptForm.value = { ...d }; deptDialog.value = true }
const saveDept = () => {
  if (!deptForm.value.name) return
  if (deptForm.value.key) {
    const idx = depts.value.findIndex(x => x.key === deptForm.value.key)
    depts.value[idx] = { ...deptForm.value }
  }
  else {
    const key = uniqueKey(slug(deptForm.value.name), depts.value.map(d => d.key))
    depts.value.push({ key, name: deptForm.value.name, parent_key: deptForm.value.parent_key || null, sort_order: depts.value.length })
  }
  dirty.value = true; deptDialog.value = false
}
const deleteDept = d => { depts.value = depts.value.filter(x => x.key !== d.key); roles.value = roles.value.map(r => r.department_key === d.key ? { ...r, department_key: null } : r); dirty.value = true }
const moveDept = (i, dir) => { const j = i + dir; if (j < 0 || j >= depts.value.length) return; const a = [...depts.value];[a[i], a[j]] = [a[j], a[i]]; depts.value = a; dirty.value = true }

const openAddRole = () => { roleForm.value = { key: null, title: '', department_key: null, level: '' }; roleDialog.value = true }
const openEditRole = r => { roleForm.value = { ...r }; roleDialog.value = true }
const saveRole = () => {
  if (!roleForm.value.title) return
  if (roleForm.value.key) {
    const idx = roles.value.findIndex(x => x.key === roleForm.value.key)
    roles.value[idx] = { ...roleForm.value }
  }
  else {
    const key = uniqueKey(slug(roleForm.value.title), roles.value.map(r => r.key))
    roles.value.push({ key, title: roleForm.value.title, department_key: roleForm.value.department_key || null, level: roleForm.value.level || null })
  }
  dirty.value = true; roleDialog.value = false
}
const deleteRole = r => { roles.value = roles.value.filter(x => x.key !== r.key); dirty.value = true }

const save = async () => {
  saving.value = true
  try {
    preset.value = await $platformApi(`/organization-presets/${route.params.id}`, {
      method: 'PUT',
      body: { structure: { departments: depts.value, job_roles: roles.value } },
    })
    dirty.value = false
    toast(t('orgPresetAdmin.saved'), 'success')
  }
  catch { /* $platformApi surfaces validation errors */ }
  finally { saving.value = false }
}

const publish = async () => {
  if (dirty.value) await save()
  try { preset.value = await $platformApi(`/organization-presets/${route.params.id}/publish`, { method: 'POST', body: {} }); toast(t('orgPresetAdmin.published'), 'success') }
  catch { /* */ }
}
const duplicate = async () => { try { const d = await $platformApi(`/organization-presets/${route.params.id}/duplicate`, { method: 'POST', body: {} }); router.push(`/platform/organization-presets/${d.id}`) } catch { /* */ } }

const statusColor = s => ({ draft: 'secondary', published: 'success', archived: 'warning' }[s] || 'secondary')

onBeforeRouteLeave(() => {
  if (dirty.value && !window.confirm(t('orgPresetAdmin.unsaved'))) return false
})

onMounted(load)
// Recharger quand on navigue vers un autre preset (ex. après « Nouvelle version »).
watch(() => route.params.id, load)
</script>

<template>
  <div v-if="preset">
    <div class="d-flex align-center justify-space-between flex-wrap gap-3 mb-4">
      <div class="d-flex align-center gap-3">
        <IconBtn @click="router.push('/platform/organization-presets')">
          <VIcon icon="tabler-arrow-left" />
        </IconBtn>
        <div>
          <h4 class="text-h5 font-weight-bold mb-0">
            {{ preset.label }}
            <VChip
              :color="statusColor(preset.status)"
              size="small"
              class="ms-2"
            >
              {{ t('orgPresetAdmin.statuses.' + preset.status) }} · v{{ preset.version }}
            </VChip>
          </h4>
          <span class="text-body-2 text-medium-emphasis">
            {{ preset.jobdomain_key }}<span v-if="preset.market_key"> · {{ preset.market_key }}</span><span v-if="preset.org_profile_key"> · {{ preset.org_profile_key }}</span>
          </span>
        </div>
      </div>
      <div class="d-flex gap-2">
        <VBtn
          v-if="!readOnly"
          variant="tonal"
          :loading="saving"
          :disabled="!dirty"
          prepend-icon="tabler-device-floppy"
          @click="save"
        >
          {{ t('common.save') }}
        </VBtn>
        <VBtn
          v-if="!readOnly"
          color="success"
          prepend-icon="tabler-rocket"
          @click="publish"
        >
          {{ t('orgPresetAdmin.publish') }}
        </VBtn>
        <VBtn
          variant="tonal"
          prepend-icon="tabler-copy"
          @click="duplicate"
        >
          {{ t('orgPresetAdmin.newVersion') }}
        </VBtn>
      </div>
    </div>

    <VAlert
      v-if="readOnly"
      type="info"
      variant="tonal"
      density="compact"
      class="mb-4"
    >
      {{ t('orgPresetAdmin.readOnlyHint') }}
    </VAlert>

    <VRow>
      <!-- Départements -->
      <VCol
        cols="12"
        md="6"
      >
        <VCard>
          <VCardTitle class="d-flex align-center">
            <VIcon
              icon="tabler-sitemap"
              class="me-2"
            />
            {{ t('orgPresetAdmin.departments') }}
            <VSpacer />
            <VBtn
              v-if="!readOnly"
              size="small"
              variant="tonal"
              prepend-icon="tabler-plus"
              @click="openAddDept"
            >
              {{ t('orgPresetAdmin.addDept') }}
            </VBtn>
          </VCardTitle>
          <VCardText>
            <div
              v-if="!depts.length"
              class="text-center text-disabled py-4"
            >
              {{ t('orgPresetAdmin.noDept') }}
            </div>
            <div
              v-for="(d, i) in depts"
              :key="d.key"
              class="d-flex align-center gap-2 py-2 border-b"
              :style="{ paddingInlineStart: (depthOf(d.key) * 20) + 'px' }"
            >
              <VIcon
                :icon="d.parent_key ? 'tabler-corner-down-right' : 'tabler-building'"
                size="18"
                class="text-disabled"
              />
              <span class="flex-grow-1">{{ d.name }}</span>
              <template v-if="!readOnly">
                <IconBtn
                  size="x-small"
                  @click="moveDept(i, -1)"
                ><VIcon icon="tabler-chevron-up" /></IconBtn>
                <IconBtn
                  size="x-small"
                  @click="moveDept(i, 1)"
                ><VIcon icon="tabler-chevron-down" /></IconBtn>
                <IconBtn
                  size="x-small"
                  @click="openEditDept(d)"
                ><VIcon icon="tabler-edit" /></IconBtn>
                <IconBtn
                  size="x-small"
                  @click="deleteDept(d)"
                ><VIcon
                  icon="tabler-trash"
                  color="error"
                /></IconBtn>
              </template>
            </div>
          </VCardText>
        </VCard>
      </VCol>

      <!-- Postes -->
      <VCol
        cols="12"
        md="6"
      >
        <VCard>
          <VCardTitle class="d-flex align-center">
            <VIcon
              icon="tabler-users-group"
              class="me-2"
            />
            {{ t('orgPresetAdmin.jobRoles') }}
            <VSpacer />
            <VBtn
              v-if="!readOnly"
              size="small"
              variant="tonal"
              prepend-icon="tabler-plus"
              @click="openAddRole"
            >
              {{ t('orgPresetAdmin.addRole') }}
            </VBtn>
          </VCardTitle>
          <VCardText>
            <div
              v-if="!roles.length"
              class="text-center text-disabled py-4"
            >
              {{ t('orgPresetAdmin.noRole') }}
            </div>
            <div
              v-for="r in roles"
              :key="r.key"
              class="d-flex align-center gap-2 py-2 border-b"
            >
              <VIcon
                icon="tabler-user"
                size="18"
                class="text-disabled"
              />
              <div class="flex-grow-1">
                <div>{{ r.title }}</div>
                <div class="text-caption text-disabled">
                  {{ depts.find(d => d.key === r.department_key)?.name || '—' }}
                </div>
              </div>
              <template v-if="!readOnly">
                <IconBtn
                  size="x-small"
                  @click="openEditRole(r)"
                ><VIcon icon="tabler-edit" /></IconBtn>
                <IconBtn
                  size="x-small"
                  @click="deleteRole(r)"
                ><VIcon
                  icon="tabler-trash"
                  color="error"
                /></IconBtn>
              </template>
            </div>
          </VCardText>
        </VCard>
      </VCol>
    </VRow>

    <!-- Dept dialog -->
    <VDialog
      v-model="deptDialog"
      max-width="480"
    >
      <VCard>
        <VCardTitle>{{ deptForm.key ? t('orgPresetAdmin.editDept') : t('orgPresetAdmin.addDept') }}</VCardTitle>
        <VCardText class="d-flex flex-column gap-3">
          <AppTextField
            v-model="deptForm.name"
            :label="t('orgPresetAdmin.deptName')"
          />
          <AppSelect
            v-model="deptForm.parent_key"
            :items="deptItems.filter(d => d.value !== deptForm.key)"
            :label="t('orgPresetAdmin.parent')"
            clearable
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="tonal"
            @click="deptDialog = false"
          >
            {{ t('common.cancel') }}
          </VBtn>
          <VBtn
            color="primary"
            :disabled="!deptForm.name"
            @click="saveDept"
          >
            {{ t('common.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>

    <!-- Role dialog -->
    <VDialog
      v-model="roleDialog"
      max-width="480"
    >
      <VCard>
        <VCardTitle>{{ roleForm.key ? t('orgPresetAdmin.editRole') : t('orgPresetAdmin.addRole') }}</VCardTitle>
        <VCardText class="d-flex flex-column gap-3">
          <AppTextField
            v-model="roleForm.title"
            :label="t('orgPresetAdmin.roleTitle')"
          />
          <AppSelect
            v-model="roleForm.department_key"
            :items="deptItems"
            :label="t('orgPresetAdmin.department')"
            clearable
          />
          <AppSelect
            v-model="roleForm.level"
            :items="[{ title: t('orgPresetAdmin.levels.manager'), value: 'manager' }, { title: t('orgPresetAdmin.levels.agent'), value: 'agent' }]"
            :label="t('orgPresetAdmin.level')"
            clearable
          />
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="tonal"
            @click="roleDialog = false"
          >
            {{ t('common.cancel') }}
          </VBtn>
          <VBtn
            color="primary"
            :disabled="!roleForm.title"
            @click="saveRole"
          >
            {{ t('common.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
