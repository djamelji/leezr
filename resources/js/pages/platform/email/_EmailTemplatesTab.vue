<script setup>
import { $platformApi } from '@/utils/platformApi'

const { toast } = useAppToast()
const { t } = useI18n()

const templates = ref([])
const isLoading = ref(true)
const categoryFilter = ref(null)
const statusFilter = ref(null)
const search = ref('')

// Edit drawer
const isDrawerOpen = ref(false)
const editingTemplate = ref(null)
const isCreating = ref(false)
const isSaving = ref(false)
const editForm = ref({
  key: '',
  category: '',
  name: '',
  subject_fr: '',
  subject_en: '',
  body_fr: '',
  body_en: '',
  variables: [],
  is_active: true,
})

const editFormRef = ref()
const variableInput = ref('')

// Preview dialog
const isPreviewOpen = ref(false)
const previewHtml = ref('')
const previewSubject = ref('')
const previewLocale = ref('fr')
const isLoadingPreview = ref(false)
const isSendingTest = ref(false)

const categoryOptions = [
  { title: t('common.all'), value: null },
  { title: 'Billing', value: 'billing' },
  { title: 'Onboarding', value: 'onboarding' },
  { title: 'Support', value: 'support' },
  { title: 'Documents', value: 'documents' },
  { title: 'Maintenance', value: 'maintenance' },
  { title: 'Members', value: 'members' },
  { title: 'Security', value: 'security' },
]

const statusOptions = [
  { title: t('common.all'), value: null },
  { title: t('email.active'), value: true },
  { title: t('email.inactive'), value: false },
]

const filteredTemplates = computed(() => {
  let list = templates.value
  if (categoryFilter.value) list = list.filter(t => t.category === categoryFilter.value)
  if (statusFilter.value !== null) list = list.filter(t => t.is_active === statusFilter.value)
  if (search.value) {
    const s = search.value.toLowerCase()
    list = list.filter(t => t.name.toLowerCase().includes(s) || t.key.toLowerCase().includes(s))
  }

  return list
})

const fetchTemplates = async () => {
  isLoading.value = true
  try {
    const data = await $platformApi('/email/templates/configurable')

    templates.value = data.templates
  }
  catch (e) {
    toast(t('email.loadError'), 'error')
  }
  finally {
    isLoading.value = false
  }
}

const openCreate = () => {
  isCreating.value = true
  editingTemplate.value = null
  editForm.value = {
    key: '',
    category: 'onboarding',
    name: '',
    subject_fr: '',
    subject_en: '',
    body_fr: '',
    body_en: '',
    variables: [],
    is_active: true,
  }
  isDrawerOpen.value = true
}

const openEdit = tpl => {
  isCreating.value = false
  editingTemplate.value = tpl
  editForm.value = {
    key: tpl.key,
    category: tpl.category,
    name: tpl.name,
    subject_fr: tpl.subject_fr,
    subject_en: tpl.subject_en,
    body_fr: tpl.body_fr,
    body_en: tpl.body_en,
    variables: [...(tpl.variables || [])],
    is_active: tpl.is_active,
  }
  isDrawerOpen.value = true
}

const addVariable = () => {
  const v = variableInput.value.trim().replace(/[^a-z0-9_]/g, '')
  if (v && !editForm.value.variables.includes(v)) {
    editForm.value.variables.push(v)
  }
  variableInput.value = ''
}

const removeVariable = idx => {
  editForm.value.variables.splice(idx, 1)
}

const saveTemplate = async () => {
  const { valid } = await editFormRef.value.validate()
  if (!valid) return

  isSaving.value = true
  try {
    if (isCreating.value) {
      await $platformApi('/email/templates/configurable', {
        method: 'POST',
        body: editForm.value,
      })
      toast(t('email.templateCreated'), 'success')
    }
    else {
      await $platformApi(`/email/templates/configurable/${editingTemplate.value.key}`, {
        method: 'PUT',
        body: {
          subject_fr: editForm.value.subject_fr,
          subject_en: editForm.value.subject_en,
          body_fr: editForm.value.body_fr,
          body_en: editForm.value.body_en,
          is_active: editForm.value.is_active,
        },
      })
      toast(t('email.templateSaved'), 'success')
    }
    isDrawerOpen.value = false
    await fetchTemplates()
  }
  catch (e) {
    toast(e.message || t('email.saveError'), 'error')
  }
  finally {
    isSaving.value = false
  }
}

const toggleActive = async tpl => {
  try {
    await $platformApi(`/email/templates/configurable/${tpl.key}`, {
      method: 'PUT',
      body: { is_active: !tpl.is_active },
    })
    tpl.is_active = !tpl.is_active
    toast(tpl.is_active ? t('email.templateEnabled') : t('email.templateDisabled'), 'success')
  }
  catch (e) {
    toast(e.message || t('email.saveError'), 'error')
  }
}

const openPreview = async tpl => {
  isPreviewOpen.value = true
  isLoadingPreview.value = true
  previewHtml.value = ''
  previewSubject.value = ''
  editingTemplate.value = tpl
  try {
    const data = await $platformApi(`/email/templates/configurable/${tpl.key}/preview?locale=${previewLocale.value}`, { method: 'POST' })

    previewHtml.value = data.html
    previewSubject.value = data.subject
  }
  catch (e) {
    previewHtml.value = `<p style="color:red">${e.message}</p>`
  }
  finally {
    isLoadingPreview.value = false
  }
}

const refreshPreview = async () => {
  if (!editingTemplate.value) return
  await openPreview(editingTemplate.value)
}

const sendTestEmail = async tpl => {
  isSendingTest.value = true
  try {
    const data = await $platformApi(`/email/templates/configurable/${tpl.key}/test?locale=fr`, { method: 'POST' })

    toast(data.message, 'success')
  }
  catch (e) {
    toast(e.message || t('email.saveError'), 'error')
  }
  finally {
    isSendingTest.value = false
  }
}

const categoryColor = cat => {
  const map = {
    billing: 'primary',
    onboarding: 'success',
    support: 'info',
    documents: 'warning',
    maintenance: 'error',
    members: 'secondary',
    security: 'error',
  }

  return map[cat] || 'secondary'
}

watch(previewLocale, refreshPreview)

onMounted(fetchTemplates)
</script>

<template>
  <div>
    <!-- Filters -->
    <VCard class="mb-6">
      <VCardText>
        <VRow>
          <VCol
            cols="12"
            sm="3"
          >
            <AppTextField
              v-model="search"
              :placeholder="t('common.search')"
              prepend-inner-icon="tabler-search"
              clearable
            />
          </VCol>
          <VCol
            cols="12"
            sm="3"
          >
            <AppSelect
              v-model="categoryFilter"
              :items="categoryOptions"
              :label="t('email.category')"
              clearable
            />
          </VCol>
          <VCol
            cols="12"
            sm="3"
          >
            <AppSelect
              v-model="statusFilter"
              :items="statusOptions"
              :label="t('email.status')"
              clearable
            />
          </VCol>
          <VCol
            cols="12"
            sm="3"
            class="d-flex align-center justify-end"
          >
            <VBtn
              color="primary"
              prepend-icon="tabler-plus"
              @click="openCreate"
            >
              {{ t('email.createTemplate') }}
            </VBtn>
          </VCol>
        </VRow>
      </VCardText>
    </VCard>

    <!-- Templates list -->
    <VCard>
      <VCardTitle class="d-flex align-center justify-space-between pa-5">
        <div class="d-flex align-center gap-2">
          <VIcon
            icon="tabler-template"
            size="24"
          />
          {{ t('email.templateCatalog') }}
        </div>
        <VChip
          size="small"
          color="primary"
          variant="tonal"
        >
          {{ filteredTemplates.length }} {{ t('email.templates').toLowerCase() }}
        </VChip>
      </VCardTitle>

      <VSkeletonLoader
        v-if="isLoading"
        type="table-heading, table-tbody"
      />

      <VTable v-else-if="filteredTemplates.length">
        <thead>
          <tr>
            <th>{{ t('email.template') }}</th>
            <th>{{ t('email.category') }}</th>
            <th>{{ t('email.status') }}</th>
            <th class="text-center">
              {{ t('common.actions') }}
            </th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="tpl in filteredTemplates"
            :key="tpl.key"
          >
            <td>
              <div class="d-flex align-center gap-2">
                <VIcon
                  icon="tabler-mail"
                  size="18"
                  class="text-medium-emphasis"
                />
                <div>
                  <div class="text-body-2 font-weight-medium">
                    {{ tpl.name }}
                  </div>
                  <code class="text-caption text-medium-emphasis">{{ tpl.key }}</code>
                </div>
              </div>
            </td>
            <td>
              <VChip
                size="small"
                variant="tonal"
                :color="categoryColor(tpl.category)"
              >
                {{ tpl.category }}
              </VChip>
            </td>
            <td>
              <VSwitch
                :model-value="tpl.is_active"
                color="success"
                density="compact"
                hide-details
                @update:model-value="toggleActive(tpl)"
              />
            </td>
            <td class="text-center">
              <VBtn
                icon
                variant="text"
                size="small"
                @click="openEdit(tpl)"
              >
                <VIcon
                  icon="tabler-pencil"
                  size="18"
                />
                <VTooltip
                  activator="parent"
                  location="top"
                >
                  {{ t('common.edit') }}
                </VTooltip>
              </VBtn>
              <VBtn
                icon
                variant="text"
                size="small"
                @click="openPreview(tpl)"
              >
                <VIcon
                  icon="tabler-eye"
                  size="18"
                />
                <VTooltip
                  activator="parent"
                  location="top"
                >
                  {{ t('email.preview') }}
                </VTooltip>
              </VBtn>
              <VBtn
                icon
                variant="text"
                size="small"
                :loading="isSendingTest"
                @click="sendTestEmail(tpl)"
              >
                <VIcon
                  icon="tabler-send"
                  size="18"
                />
                <VTooltip
                  activator="parent"
                  location="top"
                >
                  {{ t('email.sendTest') }}
                </VTooltip>
              </VBtn>
            </td>
          </tr>
        </tbody>
      </VTable>

      <div
        v-else
        class="pa-12 text-center"
      >
        <VIcon
          icon="tabler-template-off"
          size="64"
          class="text-disabled mb-4"
        />
        <p class="text-h6 text-disabled">
          {{ t('email.noTemplates') }}
        </p>
      </div>
    </VCard>

    <!-- Edit / Create Drawer -->
    <VNavigationDrawer
      v-model="isDrawerOpen"
      temporary
      location="end"
      width="560"
    >
      <div class="pa-5">
        <div class="d-flex align-center justify-space-between mb-6">
          <h6 class="text-h6">
            {{ isCreating ? t('email.createTemplate') : editForm.name }}
          </h6>
          <IconBtn
            size="small"
            @click="isDrawerOpen = false"
          >
            <VIcon icon="tabler-x" />
          </IconBtn>
        </div>

        <VForm
          ref="editFormRef"
          @submit.prevent="saveTemplate"
        >
          <!-- Create-only fields -->
          <template v-if="isCreating">
            <AppTextField
              v-model="editForm.key"
              :label="t('email.templateKey')"
              :rules="[requiredValidator]"
              :hint="t('email.templateKeyHint')"
              persistent-hint
              placeholder="category.action_name"
              class="mb-4"
            />

            <VRow class="mb-4">
              <VCol cols="6">
                <AppSelect
                  v-model="editForm.category"
                  :items="categoryOptions.filter(c => c.value)"
                  :label="t('email.category')"
                  :rules="[requiredValidator]"
                />
              </VCol>
              <VCol cols="6">
                <AppTextField
                  v-model="editForm.name"
                  :label="t('email.templateName')"
                  :rules="[requiredValidator]"
                  placeholder="Welcome Email"
                />
              </VCol>
            </VRow>

            <!-- Variables editor -->
            <div class="mb-4">
              <div class="text-body-2 font-weight-medium mb-2">
                {{ t('email.availableVariables') }}
              </div>
              <div class="d-flex flex-wrap gap-1 mb-2">
                <VChip
                  v-for="(v, i) in editForm.variables"
                  :key="v"
                  size="small"
                  variant="outlined"
                  color="primary"
                  closable
                  :text="'{{ ' + v + ' }}'"
                  @click:close="removeVariable(i)"
                />
              </div>
              <div class="d-flex gap-2">
                <AppTextField
                  v-model="variableInput"
                  :placeholder="t('email.addVariable')"
                  density="compact"
                  style="max-inline-size: 200px;"
                  @keyup.enter="addVariable"
                />
                <VBtn
                  size="small"
                  variant="tonal"
                  @click="addVariable"
                >
                  {{ t('common.add') }}
                </VBtn>
              </div>
            </div>
          </template>

          <!-- Edit: show variables read-only -->
          <template v-else-if="editForm.variables?.length">
            <div class="mb-4">
              <div class="text-body-2 font-weight-medium mb-2">
                {{ t('email.availableVariables') }}
              </div>
              <div class="d-flex flex-wrap gap-1">
                <VChip
                  v-for="v in editForm.variables"
                  :key="v"
                  size="small"
                  variant="outlined"
                  color="primary"
                  :text="'{{ ' + v + ' }}'"
                />
              </div>
            </div>
          </template>

          <VSwitch
            v-model="editForm.is_active"
            :label="t('email.active')"
            color="success"
            class="mb-4"
          />

          <VDivider class="mb-4" />

          <AppTextField
            v-model="editForm.subject_fr"
            :label="t('email.subjectFr')"
            :rules="[requiredValidator]"
            class="mb-4"
          />

          <AppTextField
            v-model="editForm.subject_en"
            :label="t('email.subjectEn')"
            class="mb-4"
          />

          <AppTextarea
            v-model="editForm.body_fr"
            :label="t('email.bodyFr')"
            :rules="[requiredValidator]"
            rows="6"
            class="mb-4"
          />

          <AppTextarea
            v-model="editForm.body_en"
            :label="t('email.bodyEn')"
            rows="6"
            class="mb-4"
          />

          <div class="d-flex gap-3 mt-6">
            <VBtn
              type="submit"
              color="primary"
              :loading="isSaving"
              :disabled="isSaving"
            >
              {{ isCreating ? t('common.create') : t('common.save') }}
            </VBtn>
            <VBtn
              variant="outlined"
              color="secondary"
              @click="isDrawerOpen = false"
            >
              {{ t('common.cancel') }}
            </VBtn>
          </div>
        </VForm>
      </div>
    </VNavigationDrawer>

    <!-- Preview Dialog -->
    <VDialog
      v-model="isPreviewOpen"
      max-width="700"
      scrollable
    >
      <VCard>
        <VCardTitle class="d-flex align-center justify-space-between pa-5">
          <span>{{ t('email.preview') }}</span>
          <div class="d-flex align-center gap-2">
            <VBtnToggle
              v-model="previewLocale"
              mandatory
              variant="outlined"
              density="compact"
            >
              <VBtn
                value="fr"
                size="small"
              >
                FR
              </VBtn>
              <VBtn
                value="en"
                size="small"
              >
                EN
              </VBtn>
            </VBtnToggle>
            <IconBtn
              size="small"
              @click="isPreviewOpen = false"
            >
              <VIcon icon="tabler-x" />
            </IconBtn>
          </div>
        </VCardTitle>

        <VDivider />

        <VCardText
          v-if="previewSubject"
          class="pb-0"
        >
          <div class="text-caption text-medium-emphasis">
            {{ t('email.subject') }}
          </div>
          <div class="text-body-1 font-weight-medium">
            {{ previewSubject }}
          </div>
        </VCardText>

        <VCardText>
          <VSkeletonLoader
            v-if="isLoadingPreview"
            type="paragraph, paragraph"
          />
          <div
            v-else
            v-html="previewHtml"
            style="max-block-size: 500px; overflow-y: auto;"
          />
        </VCardText>
      </VCard>
    </VDialog>
  </div>
</template>
