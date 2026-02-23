<script setup>
import { usePlatformMarketsStore } from '@/modules/platform-admin/markets/markets.store'
import { useAppToast } from '@/composables/useAppToast'

definePage({
  meta: {
    layout: 'platform',
    platform: true,
    navActiveLink: 'platform-languages',
  },
})

const { t } = useI18n()
const marketsStore = usePlatformMarketsStore()
const { toast } = useAppToast()

const isLoading = ref(true)

// Dialog
const isDialogOpen = ref(false)
const dialogMode = ref('create')
const dialogLoading = ref(false)

const form = ref({
  id: null,
  key: '',
  name: '',
  native_name: '',
  is_active: true,
  sort_order: 0,
})

const headers = [
  { title: t('languages.key'), key: 'key', width: '100px' },
  { title: t('languages.name'), key: 'name' },
  { title: t('languages.nativeName'), key: 'native_name' },
  { title: t('languages.isActive'), key: 'is_active', width: '100px', align: 'center' },
  { title: t('languages.marketsCount'), key: 'markets_count', width: '100px', align: 'center' },
  { title: t('common.actions'), key: 'actions', sortable: false, width: '150px' },
]

onMounted(async () => {
  try {
    await marketsStore.fetchLanguages()
  }
  finally {
    isLoading.value = false
  }
})

const openCreate = () => {
  dialogMode.value = 'create'
  form.value = { id: null, key: '', name: '', native_name: '', is_active: true, sort_order: 0 }
  isDialogOpen.value = true
}

const openEdit = lang => {
  dialogMode.value = 'edit'
  form.value = { ...lang }
  isDialogOpen.value = true
}

const handleSave = async () => {
  dialogLoading.value = true

  try {
    if (dialogMode.value === 'create') {
      const data = await marketsStore.createLanguage({ ...form.value })

      toast(data.message || t('languages.saved'), 'success')
    }
    else {
      const data = await marketsStore.updateLanguage(form.value.id, { ...form.value })

      toast(data.message || t('languages.saved'), 'success')
    }
    isDialogOpen.value = false
  }
  catch (error) {
    toast(error?.data?.message || t('common.error'), 'error')
  }
  finally {
    dialogLoading.value = false
  }
}

const handleToggleActive = async lang => {
  try {
    const data = await marketsStore.toggleLanguageActive(lang.id)

    toast(data.message, 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('common.error'), 'error')
  }
}

const handleDelete = async lang => {
  try {
    const data = await marketsStore.deleteLanguage(lang.id)

    toast(data.message || t('languages.deleted'), 'success')
  }
  catch (error) {
    toast(error?.data?.message || t('common.error'), 'error')
  }
}
</script>

<template>
  <div>
    <!-- Header -->
    <div class="d-flex align-center justify-space-between mb-6">
      <h4 class="text-h4">
        {{ t('languages.title') }}
      </h4>
      <VBtn
        color="primary"
        prepend-icon="tabler-plus"
        @click="openCreate"
      >
        {{ t('languages.addLanguage') }}
      </VBtn>
    </div>

    <!-- Languages Table -->
    <VCard>
      <VDataTable
        :headers="headers"
        :items="marketsStore.languages"
        :loading="isLoading"
        class="text-no-wrap"
      >
        <template #item.key="{ item }">
          <span class="font-weight-medium">{{ item.key }}</span>
        </template>

        <template #item.is_active="{ item }">
          <VChip
            :color="item.is_active ? 'success' : 'secondary'"
            size="small"
          >
            {{ item.is_active ? t('common.active') : t('common.inactive') }}
          </VChip>
        </template>

        <template #item.actions="{ item }">
          <VBtn
            icon="tabler-edit"
            variant="text"
            size="small"
            @click="openEdit(item)"
          />
          <VBtn
            :icon="item.is_active ? 'tabler-eye-off' : 'tabler-eye'"
            variant="text"
            size="small"
            @click="handleToggleActive(item)"
          />
          <VBtn
            icon="tabler-trash"
            variant="text"
            size="small"
            color="error"
            @click="handleDelete(item)"
          />
        </template>
      </VDataTable>
    </VCard>

    <!-- Create/Edit Dialog -->
    <VDialog
      v-model="isDialogOpen"
      max-width="500"
    >
      <VCard :title="dialogMode === 'create' ? t('languages.addLanguage') : t('common.edit')">
        <VCardText>
          <VRow>
            <VCol cols="12">
              <AppTextField
                v-model="form.key"
                :label="t('languages.key')"
                :disabled="dialogMode === 'edit'"
                placeholder="fr"
                hint="ISO 639-1 code (e.g. fr, en, de)"
              />
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <AppTextField
                v-model="form.name"
                :label="t('languages.name')"
                placeholder="French"
              />
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <AppTextField
                v-model="form.native_name"
                :label="t('languages.nativeName')"
                placeholder="Français"
              />
            </VCol>
          </VRow>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="outlined"
            @click="isDialogOpen = false"
          >
            {{ t('common.cancel') }}
          </VBtn>
          <VBtn
            color="primary"
            :loading="dialogLoading"
            @click="handleSave"
          >
            {{ t('common.save') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </div>
</template>
