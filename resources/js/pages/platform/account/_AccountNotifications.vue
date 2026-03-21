<script setup>
/**
 * Platform Notification Preferences — dual mode.
 * ADR-382: super_admin = granular per-topic, others = bundles by category.
 */
import { $platformApi } from '@/utils/platformApi'
import { useAppToast } from '@/composables/useAppToast'

const { t } = useI18n()
const { toast } = useAppToast()
const saving = ref(false)
const loading = ref(false)
const mode = ref('bundles') // 'granular' or 'bundles'

// Granular mode state (super_admin)
const localPrefs = ref([])

const categoryLabels = {
  billing: t('notifications.categoryBilling'),
  members: t('notifications.categoryMembers'),
  modules: t('notifications.categoryModules'),
  security: t('notifications.categorySecurity'),
  support: t('notifications.categorySupport'),
  system: t('notifications.categorySystem'),
}

const groupedPrefs = computed(() => {
  const groups = {}
  for (const p of localPrefs.value) {
    const cat = p.category || 'system'
    if (!groups[cat]) groups[cat] = []
    groups[cat].push(p)
  }

  return groups
})

// Bundle mode state (non-super-admin)
const localBundles = ref([])

const fetchData = async () => {
  loading.value = true
  try {
    const data = await $platformApi('/me/notification-preferences')

    mode.value = data.mode || 'bundles'

    if (mode.value === 'granular') {
      localPrefs.value = (data.preferences || []).map(p => ({
        ...p,
        in_app: (p.channels || []).includes('in_app'),
        email: (p.channels || []).includes('email'),
      }))
    }
    else {
      localBundles.value = (data.bundles || []).map(b => ({ ...b }))
    }
  }
  finally {
    loading.value = false
  }
}

onMounted(fetchData)

const save = async () => {
  saving.value = true
  try {
    if (mode.value === 'granular') {
      const prefs = localPrefs.value.map(p => ({
        topic_key: p.key,
        channels: [
          ...(p.in_app ? ['in_app'] : []),
          ...(p.email ? ['email'] : []),
        ],
      }))

      await $platformApi('/me/notification-preferences', {
        method: 'PUT',
        body: { preferences: prefs },
      })
    }
    else {
      const bundles = localBundles.value.map(b => ({
        category: b.category,
        in_app: b.locked ? true : b.in_app,
        email: b.email,
      }))

      await $platformApi('/me/notification-preferences', {
        method: 'PUT',
        body: { bundles },
      })
    }
    toast(t('common.saved'), 'success')
  }
  catch {
    toast(t('common.operationFailed'), 'error')
  }
  finally {
    saving.value = false
  }
}
</script>

<template>
  <VCard>
    <VCardItem>
      <template #prepend>
        <VAvatar
          color="primary"
          variant="tonal"
        >
          <VIcon icon="tabler-bell-cog" />
        </VAvatar>
      </template>
      <VCardTitle>{{ t('notifications.preferences') }}</VCardTitle>
      <VCardSubtitle>{{ t('platformAccount.notificationsDesc') }}</VCardSubtitle>
    </VCardItem>

    <VCardText>
      <!-- Loading -->
      <div
        v-if="loading"
        class="text-center py-8"
      >
        <VProgressCircular indeterminate />
      </div>

      <!-- ═══ Granular mode (super_admin) ═══ -->
      <template v-else-if="mode === 'granular'">
        <template
          v-for="(prefs, category) in groupedPrefs"
          :key="category"
        >
          <h6 class="text-h6 mb-3 mt-4">
            {{ categoryLabels[category] || category }}
          </h6>

          <VTable
            class="text-no-wrap mb-4"
            density="compact"
          >
            <thead>
              <tr>
                <th style="width: 400px">
                  {{ t('notifications.topic') }}
                </th>
                <th class="text-center">
                  {{ t('notifications.channelInApp') }}
                </th>
                <th class="text-center">
                  {{ t('notifications.channelEmail') }}
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="pref in prefs"
                :key="pref.key"
              >
                <td>
                  <div class="d-flex align-center gap-2">
                    <VIcon
                      :icon="pref.icon"
                      size="18"
                    />
                    {{ pref.label }}
                  </div>
                </td>
                <td class="text-center">
                  <VCheckbox
                    v-model="pref.in_app"
                    hide-details
                    density="compact"
                  />
                </td>
                <td class="text-center">
                  <VCheckbox
                    v-model="pref.email"
                    hide-details
                    density="compact"
                  />
                </td>
              </tr>
            </tbody>
          </VTable>
        </template>
      </template>

      <!-- ═══ Bundle mode (other admins) ═══ -->
      <VList
        v-else
        class="card-list"
      >
        <VListItem
          v-for="bundle in localBundles"
          :key="bundle.category"
          class="py-4"
        >
          <template #prepend>
            <VAvatar
              :color="bundle.color || 'primary'"
              variant="tonal"
              rounded
            >
              <VIcon :icon="bundle.icon" />
            </VAvatar>
          </template>

          <VListItemTitle class="font-weight-medium">
            {{ t(`notifications.platformBundle.${bundle.category}.label`) }}
          </VListItemTitle>
          <VListItemSubtitle class="text-wrap">
            {{ t(`notifications.platformBundle.${bundle.category}.description`) }}
          </VListItemSubtitle>

          <template #append>
            <div class="d-flex align-center gap-4">
              <div
                class="d-flex flex-column align-center"
                style="min-inline-size: 60px;"
              >
                <span class="text-caption text-disabled mb-1">
                  {{ t('notifications.channelInApp') }}
                </span>
                <VSwitch
                  v-model="bundle.in_app"
                  :disabled="bundle.locked"
                  hide-details
                  density="compact"
                  color="primary"
                />
              </div>
              <div
                class="d-flex flex-column align-center"
                style="min-inline-size: 60px;"
              >
                <span class="text-caption text-disabled mb-1">
                  {{ t('notifications.channelEmail') }}
                </span>
                <VSwitch
                  v-model="bundle.email"
                  hide-details
                  density="compact"
                  color="primary"
                />
              </div>
            </div>
          </template>
        </VListItem>
      </VList>

      <!-- Locked notice (bundle mode) -->
      <VAlert
        v-if="mode === 'bundles' && localBundles.some(b => b.locked)"
        type="info"
        variant="tonal"
        class="mt-4"
        density="compact"
      >
        {{ t('notifications.lockedNotice') }}
      </VAlert>
    </VCardText>

    <VCardActions v-if="!loading">
      <VSpacer />
      <VBtn
        variant="outlined"
        @click="fetchData"
      >
        {{ t('common.cancel') }}
      </VBtn>
      <VBtn
        color="primary"
        :loading="saving"
        @click="save"
      >
        {{ t('common.save') }}
      </VBtn>
    </VCardActions>
  </VCard>
</template>
