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
})

const emit = defineEmits(['update:modelValue'])

const updateField = (code, value) => {
  emit('update:modelValue', {
    ...props.modelValue,
    [code]: value,
  })
}

const getFieldValue = code => {
  return props.modelValue[code] ?? null
}

// ADR-168b: label with required asterisk
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
          <!-- String -->
          <AppTextField
            v-if="field.type === 'string'"
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
        <!-- String -->
        <AppTextField
          v-if="field.type === 'string'"
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
