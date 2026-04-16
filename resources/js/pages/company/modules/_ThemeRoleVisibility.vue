<script setup>
import { $api } from '@/utils/api'
import { useAppToast } from '@/composables/useAppToast'

const { t } = useI18n()
const { toast } = useAppToast()

const isLoading = ref(true)
const roles = ref([])
const togglingId = ref(null)

const headers = computed(() => [
  { title: t('themeSettings.role'), key: 'name' },
  { title: t('themeSettings.showToggle'), key: 'visible', align: 'end', sortable: false },
])

onMounted(async () => {
  try {
    const data = await $api('/theme/role-visibility')

    roles.value = data.roles
  }
  catch {
    toast(t('themeSettings.failedToLoad'), 'error')
  }
  finally {
    isLoading.value = false
  }
})

const toggleVisibility = async (role, newValue) => {
  togglingId.value = role.id

  try {
    const data = await $api('/theme/role-visibility', {
      method: 'PUT',
      body: {
        visibility: { [role.id]: newValue },
      },
    })

    roles.value = data.roles
    toast(t('themeSettings.updated'), 'success')
  }
  catch {
    toast(t('themeSettings.failedToUpdate'), 'error')
  }
  finally {
    togglingId.value = null
  }
}
</script>

<template>
  <VCard>
    <VCardTitle>
      <VIcon
        icon="tabler-moon"
        class="me-2"
      />
      {{ t('themeSettings.title') }}
    </VCardTitle>
    <VCardSubtitle>
      {{ t('themeSettings.subtitle') }}
    </VCardSubtitle>

    <VDataTable
      :headers="headers"
      :items="roles"
      :loading="isLoading"
      item-value="id"
      :items-per-page="-1"
      hide-default-footer
    >
      <template #item.name="{ item }">
        <span class="text-body-1 font-weight-medium">
          {{ item.name }}
        </span>
      </template>

      <template #item.visible="{ item }">
        <VSwitch
          v-can="'modules.manage'"
          :model-value="item.visible"
          :loading="togglingId === item.id"
          :disabled="togglingId !== null"
          color="primary"
          hide-details
          @update:model-value="val => toggleVisibility(item, val)"
        />
      </template>

      <template #no-data>
        <div class="text-center pa-4 text-disabled">
          {{ t('common.noData') }}
        </div>
      </template>
    </VDataTable>
  </VCard>
</template>
