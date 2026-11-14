<script setup>
// ADR-778 (Company Experience 2.0 / UX3) — DÉPÔT documentaire unique, qui connaît son contexte.
//  - depuis Documents > Entreprise : sujet « entreprise » déjà fixé ;
//  - depuis une fiche : la personne est déjà fixée, jamais redemandée ;
//  - depuis une ligne / une demande : le type est déjà fixé.
// On ne demande que le fichier, le type s'il n'est pas connu, et une date de validité quand le type
// l'exige (règle du catalogue — aucune date n'est déduite). Endpoints existants, mêmes contrôles.
import { $api } from '@/utils/api'
import { useCan } from '@/composables/useCan'
import { useAppToast } from '@/composables/useAppToast'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  // 'company' | personRef (`employee-12`, `user-3`) | null (l'utilisateur choisit)
  subject: { type: String, default: null },
  subjectName: { type: String, default: '' },
  typeCode: { type: String, default: null },
})

const emit = defineEmits(['update:modelValue', 'deposited'])

const { t, te } = useI18n()
const { can } = useCan()
const { toast } = useAppToast()

const open = computed({ get: () => props.modelValue, set: v => emit('update:modelValue', v) })
const canPickPerson = computed(() => can('members.view') || can('workforce.view'))

const target = ref('company') // company | person
const person = ref(null) // { ref, name }
const people = ref([])
const peopleLoading = ref(false)
const types = ref([])
const typesLoading = ref(false)
const typeCode = ref(null)
const files = ref([])
const expiresAt = ref('')
const saving = ref(false)
const errors = ref({})
const message = ref('')

const subjectRef = computed(() => (target.value === 'company' ? 'company' : person.value?.ref || null))
const type = computed(() => types.value.find(d => d.code === typeCode.value) || null)
const typeLabel = d => (te(`documents.type.${d.code}`) ? t(`documents.type.${d.code}`) : d.label)
const typeItems = computed(() => types.value.map(d => ({ title: typeLabel(d), value: d.code })))
const accept = computed(() => (type.value?.accepted_types || []).map(x => `.${x}`).join(','))
const ready = computed(() => subjectRef.value && type.value && files.value.length && (!type.value.requires_expiration || expiresAt.value))

const loadTypes = async () => {
  types.value = []
  if (!subjectRef.value) return
  typesLoading.value = true
  try {
    const data = await $api(subjectRef.value === 'company' ? '/company/documents' : `/company/people/${subjectRef.value}/documents`)

    types.value = data.documents || []
    if (!types.value.some(d => d.code === typeCode.value)) typeCode.value = types.value.length === 1 ? types.value[0].code : null
  }
  catch { types.value = [] }
  finally { typesLoading.value = false }
}

let timer = null
const searchPeople = term => {
  clearTimeout(timer)
  timer = setTimeout(async () => {
    peopleLoading.value = true
    try {
      const data = await $api('/company/people', { query: { search: term || '', per_page: 20 } })

      people.value = (data.data || []).map(r => ({ title: r.name, value: r.person_ref }))
    }
    catch { people.value = [] }
    finally { peopleLoading.value = false }
  }, 250)
}

watch(open, async v => {
  if (!v) return
  errors.value = {}
  message.value = ''
  files.value = []
  expiresAt.value = ''
  typeCode.value = props.typeCode
  if (props.subject && props.subject !== 'company') {
    target.value = 'person'
    person.value = { ref: props.subject, name: props.subjectName }
  }
  else {
    target.value = 'company'
    person.value = null
    if (!props.subject && canPickPerson.value) searchPeople('')
  }
  await loadTypes()
})
watch(subjectRef, (now, before) => { if (open.value && now !== before) loadTypes() })

const onFiles = e => { files.value = [...(e.target.files || [])] }

const submit = async () => {
  if (!ready.value || saving.value) return
  saving.value = true
  errors.value = {}
  message.value = ''
  try {
    const body = new FormData()

    files.value.forEach(f => body.append('files[]', f))
    if (expiresAt.value) body.append('expires_at', expiresAt.value)
    await $api(subjectRef.value === 'company' ? `/company/documents/${typeCode.value}` : `/company/people/${subjectRef.value}/documents/${typeCode.value}`, { method: 'POST', body })
    toast(t('documents.uploaded'), 'success')
    emit('deposited', { subject: subjectRef.value, typeCode: typeCode.value })
    open.value = false
  }
  catch (e) {
    errors.value = e?.data?.errors || {}
    message.value = Object.values(errors.value)[0]?.[0] || e?.data?.message || t('documents.failedToUpload')
  }
  finally { saving.value = false }
}
</script>

<template>
  <!-- ADR-783 (UX6.2) : dialogue commun (titre, fermer, pied d'actions stable, plein écran mobile) -->
  <AppDialog
    v-model="open"
    :title="t('documents.deposit.title')"
    :max-width="520"
  >
    <div data-test="document-deposit">
        <VAlert
          v-if="message"
          type="error"
          variant="tonal"
          density="compact"
          class="mb-4"
          data-test="document-deposit-error"
        >
          {{ message }}
        </VAlert>

        <!-- Sujet : affiché en clair quand il est connu, choisi sinon. -->
        <div
          v-if="props.subject"
          class="text-body-1 mb-4"
          data-test="document-deposit-subject"
        >
          <span class="text-medium-emphasis">{{ t('documents.deposit.for') }}</span>
          <span class="font-weight-medium">{{ props.subject === 'company' ? t('documents.deposit.company') : (props.subjectName || person?.name) }}</span>
        </div>
        <template v-else>
          <VRadioGroup
            v-if="canPickPerson"
            v-model="target"
            inline
            hide-details
            class="mb-3"
          >
            <VRadio
              value="company"
              :label="t('documents.deposit.company')"
              data-test="document-deposit-target-company"
            />
            <VRadio
              value="person"
              :label="t('documents.deposit.person')"
              data-test="document-deposit-target-person"
            />
          </VRadioGroup>
          <AppAutocomplete
            v-if="target === 'person'"
            :model-value="person?.ref"
            :items="people"
            :loading="peopleLoading"
            :label="t('documents.deposit.personLabel') + ' *'"
            no-filter
            class="mb-4"
            data-test="document-deposit-person"
            @update:search="searchPeople"
            @update:model-value="v => { person = v ? { ref: v, name: people.find(p => p.value === v)?.title || '' } : null }"
          />
        </template>

        <AppSelect
          v-if="!props.typeCode || !type"
          v-model="typeCode"
          :items="typeItems"
          :loading="typesLoading"
          :disabled="!subjectRef"
          :label="t('documents.deposit.type') + ' *'"
          class="mb-4"
          data-test="document-deposit-type"
        />
        <div
          v-else
          class="text-body-1 mb-4"
        >
          <span class="text-medium-emphasis">{{ t('documents.deposit.type') }} : </span>
          <span class="font-weight-medium">{{ typeLabel(type) }}</span>
        </div>

        <div class="mb-4">
          <label class="text-body-2 d-block mb-1">{{ t('documents.deposit.file') }} *</label>
          <input
            type="file"
            multiple
            :accept="accept"
            data-test="document-deposit-file"
            @change="onFiles"
          >
          <div
            v-if="type"
            class="text-caption text-medium-emphasis mt-1"
          >
            {{ (type.accepted_types || []).join(', ').toUpperCase() }}<span v-if="type.max_file_size_mb"> · {{ t('documents.maxSize', { size: type.max_file_size_mb }) }}</span>
          </div>
        </div>

        <AppTextField
          v-if="type?.requires_expiration"
          v-model="expiresAt"
          type="date"
          :label="t('documents.expiresAt') + ' *'"
          :error-messages="errors.expires_at"
          data-test="document-deposit-expires"
        />
    </div>
    <template #actions>
        <VBtn
          variant="outlined"
          color="secondary"
          @click="open = false"
        >
          {{ t('common.cancel') }}
        </VBtn>
        <VBtn
          color="primary"
          :disabled="!ready"
          :loading="saving"
          data-test="document-deposit-submit"
          @click="submit"
        >
          {{ t('documents.deposit.submit') }}
        </VBtn>
    </template>
  </AppDialog>
</template>
