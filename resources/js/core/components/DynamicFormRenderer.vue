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
</script>

<template>
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
        :label="field.label"
        :placeholder="field.label"
        :disabled="disabled"
        @update:model-value="updateField(field.code, $event)"
      />

      <!-- Number -->
      <AppTextField
        v-else-if="field.type === 'number'"
        :model-value="getFieldValue(field.code)"
        :label="field.label"
        :placeholder="field.label"
        type="number"
        :disabled="disabled"
        @update:model-value="updateField(field.code, $event)"
      />

      <!-- Boolean -->
      <VSwitch
        v-else-if="field.type === 'boolean'"
        :model-value="!!getFieldValue(field.code)"
        :label="field.label"
        :disabled="disabled"
        @update:model-value="updateField(field.code, $event)"
      />

      <!-- Date -->
      <AppDateTimePicker
        v-else-if="field.type === 'date'"
        :model-value="getFieldValue(field.code)"
        :label="field.label"
        :placeholder="field.label"
        :disabled="disabled"
        @update:model-value="updateField(field.code, $event)"
      />

      <!-- Select -->
      <AppSelect
        v-else-if="field.type === 'select'"
        :model-value="getFieldValue(field.code)"
        :label="field.label"
        :items="field.options || []"
        :placeholder="`Select ${field.label}`"
        :disabled="disabled"
        @update:model-value="updateField(field.code, $event)"
      />

      <!-- JSON -->
      <AppTextarea
        v-else-if="field.type === 'json'"
        :model-value="getFieldValue(field.code)"
        :label="field.label"
        :placeholder="field.label"
        :disabled="disabled"
        rows="3"
        @update:model-value="updateField(field.code, $event)"
      />
    </VCol>
  </template>
</template>
