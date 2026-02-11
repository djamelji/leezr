<script setup>
import { useAuthStore } from '@/core/stores/auth'

const auth = useAuthStore()

const showSwitcher = computed(() => auth.companies.length > 1)

const switchCompany = companyId => {
  auth.switchCompany(companyId)
  window.location.reload()
}
</script>

<template>
  <VMenu
    v-if="showSwitcher"
    location="bottom end"
    offset="8px"
  >
    <template #activator="{ props: menuProps }">
      <VBtn
        variant="tonal"
        color="primary"
        size="small"
        v-bind="menuProps"
      >
        <VIcon
          icon="tabler-building"
          start
        />
        {{ auth.currentCompany?.name || 'Select company' }}
        <VIcon
          icon="tabler-chevron-down"
          end
        />
      </VBtn>
    </template>

    <VList>
      <VListItem
        v-for="company in auth.companies"
        :key="company.id"
        :active="company.id === auth.currentCompanyId"
        @click="switchCompany(company.id)"
      >
        <VListItemTitle>{{ company.name }}</VListItemTitle>
        <VListItemSubtitle class="text-capitalize">
          {{ company.role }}
        </VListItemSubtitle>
      </VListItem>
    </VList>
  </VMenu>
</template>
