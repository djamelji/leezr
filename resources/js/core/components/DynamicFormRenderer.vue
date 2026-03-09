<script setup>
defineOptions({ name: 'DynamicFormRenderer' })

const props = defineProps({
  fields: {
    type: Array,
    default: () => [],
  },
  modelValue: {
    type: Object,
    default: () => ({}),
  },
  disabled: {
    type: Boolean,
    default: false,
  },
  cols: {
    type: [Number, String],
    default: 6,
  },
  dialCode: {
    type: String,
    default: '+33',
  },
})

const emit = defineEmits(['update:modelValue'])

const isPhone = field => field.pattern === 'phone'

// Format digits as spaced groups: 6 06 06 06 06
const formatPhoneDisplay = digits => {
  if (!digits) return ''
  const d = digits.replace(/\D/g, '')
  if (!d) return ''

  // Group in pairs after the first digit
  const parts = []
  parts.push(d[0])
  for (let i = 1; i < d.length; i += 2)
    parts.push(d.substring(i, i + 2))

  return parts.join(' ')
}

// Strip local prefix (leading 0) and return national digits
const toNationalDigits = raw => {
  const d = (raw || '').replace(/\D/g, '')
  if (d.startsWith('0')) return d.substring(1)

  return d
}

const handlePhoneInput = (code, raw) => {
  const national = toNationalDigits(raw)

  emit('update:modelValue', {
    ...props.modelValue,
    [code]: national ? `${props.dialCode}${national}` : '',
  })
}

// Extract national part from stored E.164 value for display
const getPhoneDisplay = code => {
  const stored = props.modelValue[code] ?? ''
  if (!stored) return ''
  let digits = stored.replace(/\D/g, '')

  // Strip the dial code digits from the front
  const dialDigits = props.dialCode.replace(/\D/g, '')
  if (digits.startsWith(dialDigits))
    digits = digits.substring(dialDigits.length)

  return formatPhoneDisplay(digits)
}

const updateField = (code, value) => {
  emit('update:modelValue', {
    ...props.modelValue,
    [code]: value,
  })
}

const getFieldValue = code => {
  return props.modelValue[code] ?? null
}

// ADR-285: label already resolved server-side with locale
const fieldLabel = field => {
  return field.required ? `${field.label} *` : field.label
}

// ADR-164: Group fields by `group` property (optional)
const hasGroups = computed(() => props.fields.some(f => f.group))

const groupedFields = computed(() => {
  if (!hasGroups.value) return null

  const groups = {}
  for (const field of props.fields) {
    const key = field.group || '_ungrouped'
    if (!groups[key]) groups[key] = []
    groups[key].push(field)
  }

  return groups
})
</script>

<template>
  <!-- Grouped rendering -->
  <template v-if="groupedFields">
    <template
      v-for="(groupFields, groupName) in groupedFields"
      :key="groupName"
    >
      <VCol
        v-if="groupName !== '_ungrouped'"
        cols="12"
      >
        <div class="text-subtitle-2 text-medium-emphasis text-capitalize mt-2">
          {{ groupName }}
        </div>
      </VCol>
      <template
        v-for="field in groupFields"
        :key="field.code"
      >
        <VCol
          :cols="12"
          :md="cols"
        >
          <!-- Phone -->
          <AppTextField
            v-if="isPhone(field)"
            :model-value="getPhoneDisplay(field.code)"
            :label="fieldLabel(field)"
            :placeholder="formatPhoneDisplay('612345678')"
            :prefix="dialCode"
            :disabled="disabled"
            type="tel"
            @update:model-value="handlePhoneInput(field.code, $event)"
          />

          <!-- String -->
          <AppTextField
            v-else-if="field.type === 'string'"
            :model-value="getFieldValue(field.code)"
            :label="fieldLabel(field)"
            :placeholder="field.label"
            :disabled="disabled"
            :append-inner-icon="field.mandatory ? 'tabler-alert-circle' : undefined"
            @update:model-value="updateField(field.code, $event)"
          />

          <!-- Number -->
          <AppTextField
            v-else-if="field.type === 'number'"
            :model-value="getFieldValue(field.code)"
            :label="fieldLabel(field)"
            :placeholder="field.label"
            type="number"
            :disabled="disabled"
            :append-inner-icon="field.mandatory ? 'tabler-alert-circle' : undefined"
            @update:model-value="updateField(field.code, $event)"
          />

          <!-- Boolean -->
          <VSwitch
            v-else-if="field.type === 'boolean'"
            :model-value="!!getFieldValue(field.code)"
            :label="fieldLabel(field)"
            :disabled="disabled"
            @update:model-value="updateField(field.code, $event)"
          />

          <!-- Date -->
          <AppDateTimePicker
            v-else-if="field.type === 'date'"
            :model-value="getFieldValue(field.code)"
            :label="fieldLabel(field)"
            :placeholder="field.label"
            :disabled="disabled"
            :append-inner-icon="field.mandatory ? 'tabler-alert-circle' : undefined"
            @update:model-value="updateField(field.code, $event)"
          />

          <!-- Select -->
          <AppSelect
            v-else-if="field.type === 'select'"
            :model-value="getFieldValue(field.code)"
            :label="fieldLabel(field)"
            :items="field.options || []"
            :placeholder="`Select ${field.label}`"
            :disabled="disabled"
            :append-inner-icon="field.mandatory ? 'tabler-alert-circle' : undefined"
            @update:model-value="updateField(field.code, $event)"
          />

          <!-- JSON -->
          <AppTextarea
            v-else-if="field.type === 'json'"
            :model-value="getFieldValue(field.code)"
            :label="fieldLabel(field)"
            :placeholder="field.label"
            :disabled="disabled"
            rows="3"
            @update:model-value="updateField(field.code, $event)"
          />
        </VCol>
      </template>
    </template>
  </template>

  <!-- Flat rendering (no groups — backward compat) -->
  <template v-else>
    <template
      v-for="field in fields"
      :key="field.code"
    >
      <VCol
        :cols="12"
        :md="cols"
      >
        <!-- Phone -->
        <AppTextField
          v-if="isPhone(field)"
          :model-value="getPhoneDisplay(field.code)"
          :label="fieldLabel(field)"
          :placeholder="formatPhoneDisplay('612345678')"
          :prefix="dialCode"
          :disabled="disabled"
          type="tel"
          @update:model-value="handlePhoneInput(field.code, $event)"
        />

        <!-- String -->
        <AppTextField
          v-else-if="field.type === 'string'"
          :model-value="getFieldValue(field.code)"
          :label="fieldLabel(field)"
          :placeholder="field.label"
          :disabled="disabled"
          :append-inner-icon="field.mandatory ? 'tabler-alert-circle' : undefined"
          @update:model-value="updateField(field.code, $event)"
        />

        <!-- Number -->
        <AppTextField
          v-else-if="field.type === 'number'"
          :model-value="getFieldValue(field.code)"
          :label="fieldLabel(field)"
          :placeholder="field.label"
          type="number"
          :disabled="disabled"
          :append-inner-icon="field.mandatory ? 'tabler-alert-circle' : undefined"
          @update:model-value="updateField(field.code, $event)"
        />

        <!-- Boolean -->
        <VSwitch
          v-else-if="field.type === 'boolean'"
          :model-value="!!getFieldValue(field.code)"
          :label="fieldLabel(field)"
          :disabled="disabled"
          @update:model-value="updateField(field.code, $event)"
        />

        <!-- Date -->
        <AppDateTimePicker
          v-else-if="field.type === 'date'"
          :model-value="getFieldValue(field.code)"
          :label="fieldLabel(field)"
          :placeholder="field.label"
          :disabled="disabled"
          :append-inner-icon="field.mandatory ? 'tabler-alert-circle' : undefined"
          @update:model-value="updateField(field.code, $event)"
        />

        <!-- Select -->
        <AppSelect
          v-else-if="field.type === 'select'"
          :model-value="getFieldValue(field.code)"
          :label="fieldLabel(field)"
          :items="field.options || []"
          :placeholder="`Select ${field.label}`"
          :disabled="disabled"
          :append-inner-icon="field.mandatory ? 'tabler-alert-circle' : undefined"
          @update:model-value="updateField(field.code, $event)"
        />

        <!-- JSON -->
        <AppTextarea
          v-else-if="field.type === 'json'"
          :model-value="getFieldValue(field.code)"
          :label="fieldLabel(field)"
          :placeholder="field.label"
          :disabled="disabled"
          rows="3"
          @update:model-value="updateField(field.code, $event)"
        />
      </VCol>
    </template>
  </template>
</template>
