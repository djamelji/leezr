<script setup>
// ADR-636 (W-E) — carte « Organisation proposée » : profil organisationnel + aperçu read-only + application
// non destructive d'un preset. Aucun recalcul côté Vue ; les montants/structures viennent de l'API.
import { $api } from '@/utils/api'
import { useAppToast } from '@/composables/useAppToast'

const emit = defineEmits(['applied'])
const { t } = useI18n()
const { toast } = useAppToast()

const profile = ref(null)
const loading = ref(false)
const previewing = ref(false)
const applying = ref(false)
const preview = ref(null)
const dialog = ref(false)
const selectedProfile = ref(null)

const loadProfile = async () => {
  loading.value = true
  try {
    profile.value = await $api('/workforce/organization/profile')
    selectedProfile.value = profile.value.org_profile_key
  }
  catch { /* handled by $api */ }
  finally { loading.value = false }
}

const saveProfile = async () => {
  try {
    await $api('/workforce/organization/profile', { method: 'PUT', body: { org_profile_key: selectedProfile.value || null } })
    toast(t('orgPreset.profileSaved'), 'success')
    await loadProfile()
  }
  catch { /* handled */ }
}

const openPreview = async () => {
  previewing.value = true
  try {
    preview.value = await $api('/workforce/organization/preset/preview')
    dialog.value = true
  }
  catch { /* handled */ }
  finally { previewing.value = false }
}

const apply = async () => {
  applying.value = true
  try {
    const res = await $api('/workforce/organization/preset/apply', { method: 'POST', body: {} })
    toast(t('orgPreset.applied', { created: res.created?.length || 0, skipped: res.skipped?.length || 0, conflicts: res.conflicts?.length || 0 }), 'success')
    dialog.value = false
    emit('applied')
    await loadProfile()
  }
  catch { /* handled */ }
  finally { applying.value = false }
}

const statusColor = s => ({ new: 'success', existing: 'secondary', customized: 'info', conflict: 'warning' }[s] || 'secondary')

const profileItems = computed(() => (profile.value?.available_profiles || []).map(p => ({ title: p.label, value: p.key })))

onMounted(loadProfile)
</script>

<template>
  <VCard
    v-if="!loading"
    class="mb-6"
    variant="tonal"
    color="primary"
  >
    <VCardText>
      <div class="d-flex align-center flex-wrap gap-4">
        <VIcon
          icon="tabler-sitemap"
          size="32"
        />
        <div class="flex-grow-1">
          <h6 class="text-h6 mb-1">
            {{ t('orgPreset.title') }}
          </h6>
          <p class="text-body-2 text-medium-emphasis mb-0">
            {{ t('orgPreset.subtitle') }}
          </p>
        </div>

        <AppSelect
          v-model="selectedProfile"
          :items="profileItems"
          :label="t('orgPreset.profile')"
          :placeholder="t('orgPreset.noProfile')"
          clearable
          density="compact"
          style="min-inline-size: 240px;"
        />
        <VBtn
          variant="tonal"
          :disabled="selectedProfile === profile?.org_profile_key"
          @click="saveProfile"
        >
          {{ t('orgPreset.saveProfile') }}
        </VBtn>
        <VBtn
          color="primary"
          :loading="previewing"
          prepend-icon="tabler-eye"
          @click="openPreview"
        >
          {{ t('orgPreset.preview') }}
        </VBtn>
      </div>

      <div
        v-if="profile?.last_applied"
        class="text-caption text-medium-emphasis mt-2"
      >
        {{ t('orgPreset.lastApplied', { key: profile.last_applied.preset_key, v: profile.last_applied.preset_version }) }}
      </div>
    </VCardText>

    <!-- Preview dialog -->
    <VDialog
      v-model="dialog"
      max-width="760"
      scrollable
    >
      <VCard>
        <VCardTitle class="d-flex align-center">
          <VIcon
            icon="tabler-sitemap"
            class="me-2"
          />
          {{ t('orgPreset.previewTitle') }}
        </VCardTitle>
        <VCardText style="max-block-size: 60vh;">
          <VAlert
            type="info"
            variant="tonal"
            density="compact"
            class="mb-4"
          >
            {{ t('orgPreset.nonDestructive') }}
          </VAlert>

          <template v-if="preview?.has_preset">
            <div class="d-flex gap-2 flex-wrap mb-4">
              <VChip
                color="success"
                size="small"
              >
                {{ t('orgPreset.new') }}: {{ preview.summary.new }}
              </VChip>
              <VChip
                color="secondary"
                size="small"
              >
                {{ t('orgPreset.existing') }}: {{ preview.summary.existing }}
              </VChip>
              <VChip
                color="info"
                size="small"
              >
                {{ t('orgPreset.customized') }}: {{ preview.summary.customized }}
              </VChip>
              <VChip
                color="warning"
                size="small"
              >
                {{ t('orgPreset.conflicts') }}: {{ preview.summary.conflicts }}
              </VChip>
            </div>

            <h6 class="text-h6 mb-2">
              {{ t('orgPreset.departments') }}
            </h6>
            <VList density="compact" class="mb-4">
              <VListItem
                v-for="d in preview.departments"
                :key="d.key"
                :title="d.name"
                :subtitle="d.parent_key ? `↳ ${d.parent_key}` : ''"
              >
                <template #append>
                  <VChip
                    :color="statusColor(d.status)"
                    size="x-small"
                  >
                    {{ t('orgPreset.status.' + d.status) }}
                  </VChip>
                </template>
              </VListItem>
            </VList>

            <h6 class="text-h6 mb-2">
              {{ t('orgPreset.jobRoles') }}
            </h6>
            <VList density="compact">
              <VListItem
                v-for="r in preview.job_roles"
                :key="r.key"
                :title="r.title"
                :subtitle="r.department_key || ''"
              >
                <template #append>
                  <VChip
                    :color="statusColor(r.status)"
                    size="x-small"
                  >
                    {{ t('orgPreset.status.' + r.status) }}
                  </VChip>
                </template>
              </VListItem>
            </VList>
          </template>
          <div
            v-else
            class="text-center text-disabled py-6"
          >
            {{ t('orgPreset.noPreset') }}
          </div>
        </VCardText>
        <VCardActions>
          <VSpacer />
          <VBtn
            variant="tonal"
            @click="dialog = false"
          >
            {{ t('common.cancel') }}
          </VBtn>
          <VBtn
            v-if="preview?.has_preset"
            color="primary"
            :loading="applying"
            prepend-icon="tabler-check"
            @click="apply"
          >
            {{ t('orgPreset.apply') }}
          </VBtn>
        </VCardActions>
      </VCard>
    </VDialog>
  </VCard>
</template>
