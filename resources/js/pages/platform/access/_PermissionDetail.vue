<script setup>
/**
 * Read-only permission detail viewer for platform roles "View" drawer.
 * Displays permissions grouped by module — pattern: DemoListSubGroup.vue preset.
 */
const props = defineProps({
  role: { type: Object, required: true },
})

const { t } = useI18n()

const accessLevelColor = level => ({
  full_access: 'error',
  administration: 'warning',
  management: 'info',
  standard: 'success',
  limited: 'secondary',
  custom: 'default',
})[level] || 'default'
</script>

<template>
  <!-- Role summary -->
  <div class="mb-4">
    <div class="d-flex align-center gap-2 mb-2">
      <VChip
        :color="accessLevelColor(role.access_level)"
        size="small"
        variant="tonal"
      >
        {{ t(`platformRoles.accessLevels.${role.access_level}`) }}
      </VChip>
      <VChip
        v-if="role.is_system"
        size="x-small"
        color="warning"
        variant="tonal"
        class="text-capitalize"
      >
        {{ t('common.system') }}
      </VChip>
    </div>
    <div class="text-body-2 text-disabled">
      {{ t('platformRoles.permissionsCount', { count: role.permissions_count }) }}
    </div>
  </div>

  <!-- super_admin info -->
  <VAlert
    v-if="role.key === 'super_admin'"
    type="info"
    variant="tonal"
    density="compact"
    class="mb-4"
  >
    {{ t('platformRoles.allPermissionsInfo') }}
  </VAlert>

  <!-- Grouped permissions -->
  <VList
    v-if="role.permissions_grouped?.length"
    density="compact"
    class="pa-0"
  >
    <template
      v-for="group in role.permissions_grouped"
      :key="group.module_key"
    >
      <VListSubheader class="text-body-2 font-weight-medium d-flex align-center">
        <VIcon
          :icon="group.module_icon"
          size="18"
          class="me-2"
        />
        {{ group.module_name }}
        <VSpacer />
        <VChip
          size="x-small"
          variant="tonal"
        >
          {{ group.permissions.length }}
        </VChip>
      </VListSubheader>

      <VListItem
        v-for="perm in group.permissions"
        :key="perm.id"
        density="compact"
        class="ps-8"
      >
        <template #prepend>
          <VIcon
            icon="tabler-point"
            size="12"
          />
        </template>
        <VListItemTitle class="text-body-2">
          {{ perm.label }}
        </VListItemTitle>
        <template #append>
          <VChip
            v-if="perm.is_admin"
            size="x-small"
            color="error"
            variant="tonal"
          >
            {{ t('common.sensitive') }}
          </VChip>
        </template>
      </VListItem>
    </template>
  </VList>

  <!-- Empty state -->
  <div
    v-else-if="role.key !== 'super_admin'"
    class="text-center pa-4 text-disabled"
  >
    {{ t('platformRoles.noPermissions') }}
  </div>
</template>
