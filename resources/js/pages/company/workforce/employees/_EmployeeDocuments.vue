<script setup>
const props = defineProps({
  employee: { type: Object, required: true },
})

const { t } = useI18n()
</script>

<template>
  <div>
    <h6 class="text-h6 mb-4">{{ $t('employees.documents.title') }}</h6>
    <div class="text-center pa-12">
      <VIcon icon="tabler-folder" size="64" color="secondary" class="mb-4" />
      <h6 class="text-h6 mb-2">{{ $t('employees.documents.title') }}</h6>
      <p class="text-body-1 text-medium-emphasis mb-4">{{ $t('employees.documents.emptyDescription') }}</p>
      <VChip color="info" variant="tonal" prepend-icon="tabler-clock" size="small">
        {{ $t('common.comingSoon') }}
      </VChip>
    </div>
  </div>
</template>
