<script setup>
definePage({ meta: { module: 'workforce_planning', permission: 'workforce.planning_view' } })
</script>

<template>
  <div>
    <div class="d-flex align-center justify-space-between mb-6">
      <div>
        <h4 class="text-h4 font-weight-bold">
          {{ $t('workforce.modules.planning.title') }}
        </h4>
        <p class="text-body-1 text-medium-emphasis mb-0">
          {{ $t('workforce.modules.planning.description') }}
        </p>
      </div>
    </div>

    <!-- Empty State -->
    <VCard variant="outlined" class="text-center pa-12">
      <VIcon
        icon="tabler-calendar-event"
        size="64"
        color="warning"
        class="mb-4"
      />
      <h5 class="text-h5 mb-2">
        {{ $t('workforce.emptyStates.planning.title') }}
      </h5>
      <p class="text-body-1 text-medium-emphasis">
        {{ $t('workforce.emptyStates.planning.description') }}
      </p>
    </VCard>
  </div>
</template>
