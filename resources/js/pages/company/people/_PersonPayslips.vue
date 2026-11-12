<script setup>
// ADR-768 (M7 LOT 2) — bulletins PUBLIÉS d'une personne, dans sa fiche. Consommateur de
// `GET /company/people/{ref}/payslips` : l'autorité paie (DocumentAccessPolicy) décide côté
// serveur — ni `documents.view` ni `members.view` ne donnent accès à cet onglet.
import { usePersonStore } from '@/modules/company/people/person.store'
import { useAppToast } from '@/composables/useAppToast'

const props = defineProps({
  personRef: { type: String, required: true },
})

const { t } = useI18n()
const store = usePersonStore()
const { toast } = useAppToast()
const downloading = ref(null)

onMounted(() => store.loadPayslips(props.personRef))
watch(() => props.personRef, r => store.loadPayslips(r))

const download = async p => {
  downloading.value = p.id
  try {
    const blob = await store.downloadPayslip(props.personRef, p.id)
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')

    a.href = url
    a.download = `bulletin-${p.period}.pdf`
    a.click()
    URL.revokeObjectURL(url)
  }
  catch { toast(t('common.error'), 'error') }
  finally { downloading.value = null }
}
</script>

<template>
  <div data-test="person-payslips">
    <VProgressLinear
      v-if="store.payslipsLoading"
      indeterminate
      color="primary"
    />
    <VTable
      v-else-if="store.payslips.length"
      density="comfortable"
    >
      <thead>
        <tr>
          <th>{{ t('documents.payslip.period') }}</th>
          <th>{{ t('documents.payslip.source') }}</th>
          <th>{{ t('documents.payslip.statusCol') }}</th>
          <th />
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="p in store.payslips"
          :key="p.id"
        >
          <td class="font-weight-medium">
            {{ p.period }}
            <VChip
              v-if="p.version_no > 1"
              size="x-small"
              class="ms-2"
              label
            >
              v{{ p.version_no }}
            </VChip>
          </td>
          <td>{{ p.source === 'external_import' ? t('documents.payslip.external') : t('documents.payslip.generated') }}</td>
          <td>
            <VChip
              size="small"
              variant="tonal"
              label
              :color="p.status === 'revoked' ? 'error' : 'success'"
            >
              {{ p.status === 'revoked' ? t('documents.payslip.revoked') : t('documents.payslip.available') }}
            </VChip>
          </td>
          <td class="text-end">
            <VBtn
              v-if="p.can_download"
              size="small"
              variant="text"
              icon="tabler-download"
              :loading="downloading === p.id"
              :aria-label="t('common.download')"
              @click="download(p)"
            />
          </td>
        </tr>
      </tbody>
    </VTable>
    <div
      v-else
      class="text-center text-medium-emphasis py-8"
    >
      <VIcon
        icon="tabler-receipt-off"
        size="40"
        class="text-disabled mb-2"
      />
      <div>{{ t('people.payslips.empty') }}</div>
    </div>
  </div>
</template>
