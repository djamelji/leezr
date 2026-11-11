<script setup>
// ADR-760 (DMS M3) — timeline de versions RÉUTILISABLE (coffre Company, fiche Collaborateur,
// Mes documents). Lit `{ current?, versions: [...] }` : version courante + versions archivées
// (n°, date, auteur, source), téléchargement gaté par la même policy serveur.
import { ref, watch } from 'vue'
import { $api } from '@/utils/api'
import { useDocumentHelpers } from '@/composables/useDocumentHelpers'

const props = defineProps({
  // Base de l'endpoint versions, ex. /company/members/5/documents/id_card ou /company/documents/kbis
  versionsUrl: { type: String, default: '' },
  autoload: { type: Boolean, default: true },
})

const { t } = useI18n()
const { formatFileSize } = useDocumentHelpers()

const loading = ref(false)
const current = ref(null)
const versions = ref([])
const loaded = ref(false)

const load = async () => {
  if (!props.versionsUrl) return
  loading.value = true
  try {
    const data = await $api(props.versionsUrl)

    current.value = data.current || null
    versions.value = data.versions || []
    loaded.value = true
  }
  catch { versions.value = []; current.value = null }
  finally { loading.value = false }
}

watch(() => props.versionsUrl, () => { if (props.autoload) load() }, { immediate: true })

const fmtDate = iso => (iso ? new Date(iso).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' }) : '')
</script>

<template>
  <div class="document-versions-timeline">
    <div class="d-flex align-center justify-space-between mb-2">
      <span class="text-body-1 font-weight-medium">{{ t('documents.versionHistory') }}</span>
      <VProgressCircular
        v-if="loading"
        indeterminate
        size="16"
        width="2"
      />
    </div>

    <VTimeline
      v-if="versions.length || current"
      density="compact"
      side="end"
      truncate-line="both"
      class="ms-1"
    >
      <!-- Version courante -->
      <VTimelineItem
        v-if="current"
        dot-color="success"
        size="x-small"
      >
        <div class="d-flex justify-space-between gap-2 flex-wrap">
          <span class="text-body-2 font-weight-medium">
            {{ t('documents.currentVersion') }} · {{ current.file_name }}
          </span>
          <span class="text-caption text-medium-emphasis">{{ fmtDate(current.uploaded_at) }}</span>
        </div>
        <div class="text-caption text-medium-emphasis">
          {{ formatFileSize(current.file_size_bytes) }}
          <span v-if="current.author"> · {{ current.author }}</span>
        </div>
      </VTimelineItem>

      <!-- Versions archivées -->
      <VTimelineItem
        v-for="v in versions"
        :key="v.id"
        dot-color="secondary"
        size="x-small"
      >
        <div class="d-flex justify-space-between gap-2 flex-wrap align-center">
          <span class="text-body-2">
            v{{ v.version_no }} · {{ v.file_name }}
          </span>
          <div class="d-flex align-center gap-2">
            <span class="text-caption text-medium-emphasis">{{ fmtDate(v.archived_at) }}</span>
            <VBtn
              :href="`${versionsUrl}/${v.id}/download`"
              target="_blank"
              icon
              size="x-small"
              variant="text"
            >
              <VIcon
                icon="tabler-download"
                size="16"
              />
            </VBtn>
          </div>
        </div>
        <div class="text-caption text-medium-emphasis">
          {{ formatFileSize(v.file_size_bytes) }}
          <span v-if="v.author"> · {{ v.author }}</span>
          <span v-if="v.source"> · {{ v.source }}</span>
        </div>
      </VTimelineItem>
    </VTimeline>

    <div
      v-else-if="loaded && !loading"
      class="text-body-2 text-medium-emphasis py-2"
    >
      {{ t('documents.noVersionHistory') }}
    </div>
  </div>
</template>
