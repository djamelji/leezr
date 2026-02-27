<script setup>
/**
 * Shared Permission Matrix — used by both company/roles and platform/roles.
 * Renders module-grouped permissions with Simple/Advanced toggle.
 * ADR-132: Unified Permission Architecture.
 *
 * Section order (company): Addons → Core → Unbundled → Disabled
 * Section order (platform): Core → Unbundled (no addons, no disabled)
 */
const props = defineProps({
  permissionCatalog: { type: Array, required: true },
  permissionModules: { type: Array, required: true },
  selectedPermissions: { type: Array, required: true },
  isAdministrative: { type: Boolean, default: true },
  scope: { type: String, default: 'company', validator: v => ['company', 'platform'].includes(v) },
})

const emit = defineEmits(['update:selectedPermissions'])

const { t } = useI18n()
const te = useI18n().te
const isAdvancedMode = ref(false)

// ─── i18n helpers ─────
const modName = key => te(`permissionCatalog.modules.${key}.name`) ? t(`permissionCatalog.modules.${key}.name`) : null
const modDesc = key => te(`permissionCatalog.modules.${key}.description`) ? t(`permissionCatalog.modules.${key}.description`) : null
const capLabel = key => te(`permissionCatalog.bundles.${key}.label`) ? t(`permissionCatalog.bundles.${key}.label`) : null
const capHint = key => te(`permissionCatalog.bundles.${key}.hint`) ? t(`permissionCatalog.bundles.${key}.hint`) : null
const permLabel = key => te(`permissionCatalog.permissions.${key}.label`) ? t(`permissionCatalog.permissions.${key}.label`) : null
const permHint = key => te(`permissionCatalog.permissions.${key}.hint`) ? t(`permissionCatalog.permissions.${key}.hint`) : null

// ─── Module icon lookup ─────
const moduleIconMap = computed(() => {
  const map = {}
  for (const m of props.permissionModules) {
    map[m.module_key] = m.module_icon
  }

  return map
})

// ─── Simple mode: Capability bundles per module ─────
const capabilityModules = computed(() => {
  return props.permissionModules
    .filter(m => m.module_active)
    .map(mod => ({
      ...mod,
      capabilities: mod.capabilities
        .filter(cap => props.isAdministrative || !cap.is_admin),
    }))
    .filter(m => m.capabilities.length > 0)
})

const coreModules = computed(() => capabilityModules.value.filter(m => m.is_core))
const businessModules = computed(() => capabilityModules.value.filter(m => !m.is_core))

// ─── Simple mode: disabled modules (company scope only) ─────
const disabledModules = computed(() => {
  if (props.scope === 'platform') return []

  return props.permissionModules
    .filter(m => !m.module_active)
    .map(mod => ({
      ...mod,
      capabilities: mod.capabilities
        .filter(cap => props.isAdministrative || !cap.is_admin),
    }))
    .filter(m => m.capabilities.length > 0)
})

// ─── Simple mode: unbundled permissions (modules with perms but no bundles) ─────
const unbundledModules = computed(() => {
  const bundledModuleKeys = new Set(capabilityModules.value.map(m => m.module_key))
  const groups = {}

  for (const p of props.permissionCatalog) {
    if (!p.module_active) continue
    if (bundledModuleKeys.has(p.module_key)) continue
    if (!props.isAdministrative && p.is_admin) continue

    if (!groups[p.module_key]) {
      groups[p.module_key] = {
        module_key: p.module_key,
        name: p.module_name,
        description: p.module_description || '',
        isCore: p.module_key.startsWith('core.'),
        module_icon: moduleIconMap.value[p.module_key] || null,
        permissions: [],
      }
    }
    groups[p.module_key].permissions.push(p)
  }

  return Object.values(groups)
})

// ─── Simple mode sections (ordered: addons → core → platform-ext → disabled) ─────
// Unbundled modules are distributed into their parent section (core vs non-core)
// to avoid duplicate section headers.
const simpleCapSections = computed(() => {
  const sections = []
  const coreUnbundled = unbundledModules.value.filter(m => m.isCore)
  const nonCoreUnbundled = unbundledModules.value.filter(m => !m.isCore)

  // Addons first (company scope only, active non-core)
  if (props.scope === 'company' && (businessModules.value.length || nonCoreUnbundled.length)) {
    sections.push({
      key: 'addons',
      label: t('roles.addonModules'),
      icon: 'tabler-cube-plus',
      iconColor: 'success',
      modules: businessModules.value,
      unbundled: nonCoreUnbundled,
      disabled: false,
    })
  }

  // Core modules
  if (coreModules.value.length || coreUnbundled.length) {
    sections.push({
      key: 'core',
      label: props.scope === 'platform' ? t('roles.platformModules') : t('roles.coreTeamCompany'),
      icon: 'tabler-building',
      iconColor: 'primary',
      modules: coreModules.value,
      unbundled: coreUnbundled,
      disabled: false,
    })
  }

  // Non-core for platform only (in company, non-core active = addons shown above)
  if (props.scope === 'platform' && (businessModules.value.length || nonCoreUnbundled.length)) {
    sections.push({
      key: 'platform-ext',
      label: t('roles.platformModules'),
      icon: 'tabler-package',
      iconColor: 'primary',
      modules: businessModules.value,
      unbundled: nonCoreUnbundled,
      disabled: false,
    })
  }

  // Disabled modules (company only, always last)
  if (disabledModules.value.length) {
    sections.push({
      key: 'disabled',
      label: t('roles.disabledModules'),
      icon: 'tabler-alert-triangle',
      iconColor: 'warning',
      modules: disabledModules.value,
      unbundled: [],
      disabled: true,
    })
  }

  return sections
})

// ─── Advanced mode: Module → Capability → Permission tree builder ─────
const buildAdvancedTree = moduleFilter => {
  const filteredModules = props.permissionModules.filter(moduleFilter)
  const moduleKeys = new Set(filteredModules.map(m => m.module_key))

  const permByKey = {}
  for (const p of props.permissionCatalog) {
    if (!moduleKeys.has(p.module_key)) continue
    if (!props.isAdministrative && p.is_admin) continue
    permByKey[p.key] = p
  }

  const result = []

  for (const mod of filteredModules) {
    const caps = []
    const bundledPermKeys = new Set()

    for (const cap of mod.capabilities) {
      if (!props.isAdministrative && cap.is_admin) continue

      const leafPerms = (cap.permissions || [])
        .map(key => permByKey[key])
        .filter(Boolean)

      if (leafPerms.length === 0) continue

      caps.push({
        key: cap.key,
        label: cap.label,
        hint: cap.hint || '',
        is_admin: cap.is_admin || false,
        permission_ids: cap.permission_ids || [],
        leafPermissions: leafPerms,
      })

      for (const key of cap.permissions) {
        bundledPermKeys.add(key)
      }
    }

    const orphans = props.permissionCatalog.filter(p =>
      p.module_key === mod.module_key
      && moduleKeys.has(p.module_key)
      && !bundledPermKeys.has(p.key)
      && (props.isAdministrative || !p.is_admin),
    )

    if (caps.length === 0 && orphans.length === 0) continue

    result.push({
      module_key: mod.module_key,
      name: mod.module_name,
      description: mod.module_description || '',
      isCore: mod.is_core,
      module_icon: mod.module_icon,
      capabilities: caps,
      orphanPermissions: orphans,
    })
  }

  return result
}

const advancedGroups = computed(() => buildAdvancedTree(m => m.module_active))
const disabledAdvancedGroups = computed(() => {
  if (props.scope === 'platform') return []

  return buildAdvancedTree(m => !m.module_active)
})

// ─── Advanced mode sections ─────
const advancedSections = computed(() => {
  const sections = []
  const addonGroups = advancedGroups.value.filter(g => !g.isCore)
  const coreGroups = advancedGroups.value.filter(g => g.isCore)

  if (props.scope === 'company' && addonGroups.length) {
    sections.push({
      key: 'addons',
      label: t('roles.addonModules'),
      icon: 'tabler-cube-plus',
      iconColor: 'success',
      groups: addonGroups,
      disabled: false,
    })
  }

  if (coreGroups.length) {
    sections.push({
      key: 'core',
      label: props.scope === 'platform' ? t('roles.platformModules') : t('roles.coreTeamCompany'),
      icon: 'tabler-building',
      iconColor: 'primary',
      groups: coreGroups,
      disabled: false,
    })
  }

  if (props.scope === 'platform' && addonGroups.length) {
    sections.push({
      key: 'platform-ext',
      label: t('roles.platformModules'),
      icon: 'tabler-package',
      iconColor: 'info',
      groups: addonGroups,
      disabled: false,
    })
  }

  if (disabledAdvancedGroups.value.length) {
    sections.push({
      key: 'disabled',
      label: t('roles.disabledModules'),
      icon: 'tabler-alert-triangle',
      iconColor: 'warning',
      groups: disabledAdvancedGroups.value,
      disabled: true,
    })
  }

  return sections
})

// ─── Per-module selection counters (all modules, including disabled) ─────
const moduleSelectionCounts = computed(() => {
  const counts = {}
  const selected = new Set(props.selectedPermissions)

  for (const p of props.permissionCatalog) {
    if (!props.isAdministrative && p.is_admin) continue

    if (!counts[p.module_key]) {
      counts[p.module_key] = { selected: 0, total: 0 }
    }
    counts[p.module_key].total++
    if (selected.has(p.id)) {
      counts[p.module_key].selected++
    }
  }

  return counts
})

// ─── Capability state: 'checked' | 'unchecked' | 'custom' ─────
const getCapabilityState = cap => {
  if (!cap.permission_ids?.length) return 'unchecked'

  const selected = new Set(props.selectedPermissions)
  const allChecked = cap.permission_ids.every(id => selected.has(id))
  const noneChecked = cap.permission_ids.every(id => !selected.has(id))

  if (allChecked) return 'checked'
  if (noneChecked) return 'unchecked'

  return 'custom'
}

const toggleCapability = cap => {
  if (!cap.permission_ids?.length) return

  const selected = new Set(props.selectedPermissions)
  const state = getCapabilityState(cap)

  if (state === 'unchecked') {
    cap.permission_ids.forEach(id => selected.add(id))
  }
  else {
    cap.permission_ids.forEach(id => selected.delete(id))
  }

  emit('update:selectedPermissions', [...selected])
}

const isPermissionChecked = permId => {
  return props.selectedPermissions.includes(permId)
}

const togglePermission = permId => {
  const current = [...props.selectedPermissions]
  const idx = current.indexOf(permId)

  if (idx === -1) {
    current.push(permId)
  }
  else {
    current.splice(idx, 1)
  }

  emit('update:selectedPermissions', current)
}
</script>

<template>
  <!-- Capabilities header + mode toggle -->
  <div class="d-flex align-center justify-space-between mb-4">
    <h6 class="text-h6">
      {{ t('roles.capabilities') }}
    </h6>
    <VBtn
      variant="text"
      size="small"
      color="default"
      :prepend-icon="isAdvancedMode ? 'tabler-layout-grid' : 'tabler-adjustments'"
      @click="isAdvancedMode = !isAdvancedMode"
    >
      {{ isAdvancedMode ? t('roles.simpleView') : t('roles.advanced') }}
    </VBtn>
  </div>

  <!-- ═══ SIMPLE MODE ═══ -->
  <template v-if="!isAdvancedMode">
    <!-- Capability-based sections (addons → core → disabled) -->
    <template
      v-for="(section, sIdx) in simpleCapSections"
      :key="section.key"
    >
      <VDivider
        v-if="sIdx > 0"
        class="mb-3"
      />

      <div class="d-flex align-center gap-2 mb-3">
        <VIcon
          :icon="section.icon"
          size="20"
          :color="section.iconColor"
        />
        <span class="text-body-1 font-weight-medium">{{ section.label }}</span>
      </div>

      <VExpansionPanels
        multiple
        variant="accordion"
        class="mb-4"
      >
        <VExpansionPanel
          v-for="mod in section.modules"
          :key="mod.module_key"
        >
          <VExpansionPanelTitle>
            <div class="d-flex align-center gap-2 flex-grow-1 me-2">
              <VIcon
                :icon="section.disabled ? 'tabler-alert-triangle' : (mod.module_icon || 'tabler-puzzle')"
                size="18"
                :color="section.disabled ? 'warning' : section.iconColor"
              />
              <span
                class="text-body-2 font-weight-medium"
                :class="{ 'text-disabled': section.disabled }"
              >
                {{ modName(mod.module_key) || mod.module_name }}
              </span>
              <VChip
                v-if="section.disabled"
                size="x-small"
                color="warning"
                variant="tonal"
              >
                {{ t('roles.moduleInactive') }}
              </VChip>
              <VSpacer />
              <VChip
                size="x-small"
                :color="(moduleSelectionCounts[mod.module_key]?.selected || 0) > 0 ? 'primary' : 'default'"
                variant="tonal"
              >
                {{ moduleSelectionCounts[mod.module_key]?.selected || 0 }} / {{ moduleSelectionCounts[mod.module_key]?.total || 0 }}
              </VChip>
            </div>
          </VExpansionPanelTitle>
          <VExpansionPanelText>
            <div
              v-if="mod.module_description"
              class="text-body-2 text-disabled mb-2"
            >
              {{ modDesc(mod.module_key) || mod.module_description }}
            </div>
            <div
              v-for="cap in mod.capabilities"
              :key="cap.key"
              class="d-flex align-center"
            >
              <VCheckbox
                :model-value="getCapabilityState(cap) === 'checked'"
                :indeterminate="getCapabilityState(cap) === 'custom'"
                hide-details
                density="compact"
                @update:model-value="toggleCapability(cap)"
              >
                <template #label>
                  <span>{{ capLabel(cap.key) || cap.label }}</span>
                  <VTooltip
                    v-if="cap.hint"
                    location="top"
                  >
                    <template #activator="{ props: tp }">
                      <VIcon
                        icon="tabler-info-circle"
                        size="14"
                        class="ms-1 text-disabled"
                        v-bind="tp"
                      />
                    </template>
                    {{ capHint(cap.key) || cap.hint }}
                  </VTooltip>
                </template>
              </VCheckbox>
              <VSpacer />
              <VChip
                v-if="getCapabilityState(cap) === 'custom'"
                size="x-small"
                color="warning"
                variant="tonal"
              >
                {{ t('common.custom') }}
              </VChip>
              <VChip
                v-else-if="cap.is_admin"
                size="x-small"
                color="error"
                variant="tonal"
              >
                {{ t('common.sensitive') }}
              </VChip>
            </div>
          </VExpansionPanelText>
        </VExpansionPanel>

        <!-- Unbundled modules (flat permissions, no capability bundles) -->
        <VExpansionPanel
          v-for="mod in section.unbundled"
          :key="mod.module_key"
        >
          <VExpansionPanelTitle>
            <div class="d-flex align-center gap-2 flex-grow-1 me-2">
              <VIcon
                :icon="section.disabled ? 'tabler-alert-triangle' : (mod.module_icon || 'tabler-puzzle')"
                size="18"
                :color="section.disabled ? 'warning' : section.iconColor"
              />
              <span
                class="text-body-2 font-weight-medium"
                :class="{ 'text-disabled': section.disabled }"
              >
                {{ modName(mod.module_key) || mod.name }}
              </span>
              <VChip
                v-if="section.disabled"
                size="x-small"
                color="warning"
                variant="tonal"
              >
                {{ t('roles.moduleInactive') }}
              </VChip>
              <VSpacer />
              <VChip
                size="x-small"
                :color="(moduleSelectionCounts[mod.module_key]?.selected || 0) > 0 ? 'primary' : 'default'"
                variant="tonal"
              >
                {{ moduleSelectionCounts[mod.module_key]?.selected || 0 }} / {{ moduleSelectionCounts[mod.module_key]?.total || 0 }}
              </VChip>
            </div>
          </VExpansionPanelTitle>
          <VExpansionPanelText>
            <div
              v-if="mod.description"
              class="text-body-2 text-disabled mb-2"
            >
              {{ modDesc(mod.module_key) || mod.description }}
            </div>
            <div
              v-for="perm in mod.permissions"
              :key="perm.id"
              class="d-flex align-center"
            >
              <VCheckbox
                :model-value="isPermissionChecked(perm.id)"
                hide-details
                density="compact"
                @update:model-value="togglePermission(perm.id)"
              >
                <template #label>
                  <span>{{ permLabel(perm.key) || perm.label }}</span>
                  <VTooltip
                    v-if="perm.hint"
                    location="top"
                  >
                    <template #activator="{ props: tp }">
                      <VIcon
                        icon="tabler-info-circle"
                        size="14"
                        class="ms-1 text-disabled"
                        v-bind="tp"
                      />
                    </template>
                    {{ permHint(perm.key) || perm.hint }}
                  </VTooltip>
                </template>
              </VCheckbox>
              <VSpacer />
              <VChip
                v-if="perm.is_admin"
                size="x-small"
                color="error"
                variant="tonal"
              >
                {{ t('common.sensitive') }}
              </VChip>
            </div>
          </VExpansionPanelText>
        </VExpansionPanel>
      </VExpansionPanels>
    </template>
  </template>
  <template v-else>
    <!-- ═══ ADVANCED MODE ═══ -->
    <template
      v-for="(section, sIdx) in advancedSections"
      :key="section.key"
    >
      <VDivider
        v-if="sIdx > 0"
        class="mb-3"
      />

      <div class="d-flex align-center gap-2 mb-3">
        <VIcon
          :icon="section.icon"
          size="20"
          :color="section.iconColor"
        />
        <span class="text-body-1 font-weight-medium">{{ section.label }}</span>
      </div>

      <VExpansionPanels
        multiple
        variant="accordion"
        class="mb-4"
      >
        <VExpansionPanel
          v-for="group in section.groups"
          :key="group.module_key"
        >
          <VExpansionPanelTitle>
            <div class="d-flex align-center gap-2 flex-grow-1 me-2">
              <VIcon
                :icon="section.disabled ? 'tabler-alert-triangle' : (group.module_icon || 'tabler-puzzle')"
                size="18"
                :color="section.disabled ? 'warning' : section.iconColor"
              />
              <span
                class="text-body-2 font-weight-medium"
                :class="{ 'text-disabled': section.disabled }"
              >
                {{ modName(group.module_key) || group.name }}
              </span>
              <VChip
                v-if="section.disabled"
                size="x-small"
                color="warning"
                variant="tonal"
              >
                {{ t('roles.moduleInactive') }}
              </VChip>
              <VSpacer />
              <VChip
                size="x-small"
                :color="(moduleSelectionCounts[group.module_key]?.selected || 0) > 0 ? 'primary' : 'default'"
                variant="tonal"
              >
                {{ moduleSelectionCounts[group.module_key]?.selected || 0 }} / {{ moduleSelectionCounts[group.module_key]?.total || 0 }}
              </VChip>
            </div>
          </VExpansionPanelTitle>
          <VExpansionPanelText>
            <div
              v-if="group.description"
              class="text-body-2 text-disabled mb-2"
            >
              {{ modDesc(group.module_key) || group.description }}
            </div>

            <!-- Capabilities with leaf permissions -->
            <div
              v-for="cap in group.capabilities"
              :key="cap.key"
              class="mb-3"
            >
              <div class="d-flex align-center">
                <VCheckbox
                  :model-value="getCapabilityState(cap) === 'checked'"
                  :indeterminate="getCapabilityState(cap) === 'custom'"
                  hide-details
                  density="compact"
                  @update:model-value="toggleCapability(cap)"
                >
                  <template #label>
                    <span class="font-weight-medium">{{ capLabel(cap.key) || cap.label }}</span>
                    <VTooltip
                      v-if="cap.hint"
                      location="top"
                    >
                      <template #activator="{ props: tp }">
                        <VIcon
                          icon="tabler-info-circle"
                          size="14"
                          class="ms-1 text-disabled"
                          v-bind="tp"
                        />
                      </template>
                      {{ capHint(cap.key) || cap.hint }}
                    </VTooltip>
                  </template>
                </VCheckbox>
                <VSpacer />
                <VChip
                  v-if="getCapabilityState(cap) === 'custom'"
                  size="x-small"
                  color="warning"
                  variant="tonal"
                >
                  {{ t('common.custom') }}
                </VChip>
                <VChip
                  v-else-if="cap.is_admin"
                  size="x-small"
                  color="error"
                  variant="tonal"
                >
                  {{ t('common.sensitive') }}
                </VChip>
              </div>

              <!-- Leaf permissions (indented) -->
              <div class="ms-7">
                <div
                  v-for="perm in cap.leafPermissions"
                  :key="perm.id"
                  class="d-flex align-center"
                >
                  <VCheckbox
                    :model-value="isPermissionChecked(perm.id)"
                    hide-details
                    density="compact"
                    @update:model-value="togglePermission(perm.id)"
                  >
                    <template #label>
                      <span>{{ permLabel(perm.key) || perm.label }}</span>
                      <VTooltip
                        v-if="perm.hint"
                        location="top"
                      >
                        <template #activator="{ props: tp }">
                          <VIcon
                            icon="tabler-info-circle"
                            size="14"
                            class="ms-1 text-disabled"
                            v-bind="tp"
                          />
                        </template>
                        {{ permHint(perm.key) || perm.hint }}
                      </VTooltip>
                    </template>
                  </VCheckbox>
                  <VSpacer />
                  <VChip
                    v-if="perm.is_admin"
                    size="x-small"
                    color="error"
                    variant="tonal"
                  >
                    {{ t('common.sensitive') }}
                  </VChip>
                </div>
              </div>
            </div>

            <!-- Orphan permissions (not in any bundle) -->
            <template v-if="group.orphanPermissions.length">
              <VDivider
                v-if="group.capabilities.length"
                class="mb-2"
              />
              <div
                v-for="perm in group.orphanPermissions"
                :key="perm.id"
                class="d-flex align-center"
              >
                <VCheckbox
                  :model-value="isPermissionChecked(perm.id)"
                  hide-details
                  density="compact"
                  @update:model-value="togglePermission(perm.id)"
                >
                  <template #label>
                    <span>{{ permLabel(perm.key) || perm.label }}</span>
                    <VTooltip
                      v-if="perm.hint"
                      location="top"
                    >
                      <template #activator="{ props: tp }">
                        <VIcon
                          icon="tabler-info-circle"
                          size="14"
                          class="ms-1 text-disabled"
                          v-bind="tp"
                        />
                      </template>
                      {{ permHint(perm.key) || perm.hint }}
                    </VTooltip>
                  </template>
                </VCheckbox>
                <VSpacer />
                <VChip
                  v-if="perm.is_admin"
                  size="x-small"
                  color="error"
                  variant="tonal"
                >
                  {{ t('common.sensitive') }}
                </VChip>
              </div>
            </template>
          </VExpansionPanelText>
        </VExpansionPanel>
      </VExpansionPanels>
    </template>
  </template>
</template>
