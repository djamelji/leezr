<script setup>
// ADR-818 — mode de calcul des exigences de la société : rôle (historique) ou poste (cible). Aperçu calculé par
// le serveur (personne par personne), bascule explicite et réversible réservée à `settings.manage`.
import { $api } from '@/utils/api'
import { useAppToast } from '@/composables/useAppToast'

const { t, te } = useI18n()
const { can } = useCan()
const { toast } = useAppToast()
const docLabel = d => (te(`documents.type.${d.code}`) ? t(`documents.type.${d.code}`) : d.label)

const data = ref(null)
const shown = ref(false)
const busy = ref(false)
const load = async () => { data.value = await $api('/workforce/requirements/authority').catch(() => null) }
onMounted(load)

const set = async (authority, acceptLosses = false) => {
  busy.value = true
  try {
    await $api('/workforce/requirements/authority', { method: 'PUT', body: { authority, accept_losses: acceptLosses } })
    toast(t('requirementAuthority.switched'), 'success')
    await load()
  }
  catch (e) { toast(Object.values(e?.data?.errors || {})[0]?.[0] || e?.data?.message || t('common.error'), 'error') }
  finally { busy.value = false }
}
</script>

<template>
  <AppSettingsGroup
    v-if="data"
    :title="t('requirementAuthority.title')"
    :description="data.authority === 'position' ? t('requirementAuthority.position') : t('requirementAuthority.role')"
    class="mb-6"
    data-test="requirements-authority"
  >
    <div class="pa-5">
      <template v-if="data.authority === 'role'">
        <VBtn
          v-if="!shown"
          variant="tonal"
          data-test="requirements-authority-preview"
          @click="shown = true"
        >
          {{ t('requirementAuthority.preview') }}
        </VBtn>
        <template v-else>
          <p
            class="mb-3"
            data-test="requirements-authority-people"
          >
            {{ t('requirementAuthority.people', { t: data.people.total, u: data.people.unchanged, c: data.people.changed }) }}
          </p>
          <VRow>
            <VCol
              cols="12"
              md="6"
            >
              <div class="text-subtitle-2 mb-1">
                {{ t('requirementAuthority.gained') }}
              </div>
              <div
                v-for="g in data.gained"
                :key="g.code"
                class="text-body-2"
              >
                {{ docLabel(g) }} · {{ g.people }}
              </div>
              <div
                v-if="!data.gained.length"
                class="text-body-2 text-medium-emphasis"
              >
                {{ t('requirementAuthority.none') }}
              </div>
            </VCol>
            <VCol
              cols="12"
              md="6"
            >
              <div class="text-subtitle-2 mb-1">
                {{ t('requirementAuthority.lost') }}
              </div>
              <div
                v-for="l in data.lost"
                :key="l.code"
                class="text-body-2 text-warning"
                data-test="requirements-authority-lost"
              >
                {{ docLabel(l) }} · {{ l.people }}
              </div>
              <div
                v-if="!data.lost.length"
                class="text-body-2 text-medium-emphasis"
              >
                {{ t('requirementAuthority.none') }}
              </div>
            </VCol>
          </VRow>
          <VAlert
            :type="data.ready ? 'success' : 'warning'"
            variant="tonal"
            density="compact"
            class="my-3"
          >
            {{ data.ready ? t('requirementAuthority.ready') : t('requirementAuthority.lostHint') }}
          </VAlert>
          <div
            v-if="can('settings.manage')"
            class="d-flex gap-2 flex-wrap"
          >
            <VBtn
              v-if="data.ready"
              color="primary"
              :loading="busy"
              data-test="requirements-authority-switch"
              @click="set('position')"
            >
              {{ t('requirementAuthority.switch') }}
            </VBtn>
            <VBtn
              v-else
              color="warning"
              variant="tonal"
              :loading="busy"
              data-test="requirements-authority-switch-anyway"
              @click="set('position', true)"
            >
              {{ t('requirementAuthority.switchAnyway') }}
            </VBtn>
          </div>
        </template>
      </template>
      <VBtn
        v-else-if="can('settings.manage')"
        variant="tonal"
        color="secondary"
        :loading="busy"
        data-test="requirements-authority-back"
        @click="set('role')"
      >
        {{ t('requirementAuthority.back') }}
      </VBtn>
      <p class="text-caption text-medium-emphasis mt-3 mb-0">
        {{ t('requirementAuthority.note') }}
      </p>
    </div>
  </AppSettingsGroup>
</template>
