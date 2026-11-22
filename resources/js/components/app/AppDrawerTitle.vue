<script setup>
// ADR-861 (L7.9) — drawer header : title, optional slot, a close button WITH an accessible name. Same API as the
// Vuexy @core AppDrawerHeaderSection (which cannot be modified and whose close button has no name).
defineProps({
  title: { type: String, required: true },
})
defineEmits(['cancel'])
</script>

<template>
  <div class="pa-6 d-flex align-center">
    <h5 class="text-h5">
      {{ title }}
    </h5>
    <VSpacer />

    <slot name="beforeClose" />

    <IconBtn
      size="small"
      :aria-label="$t('common.close')"
      :title="$t('common.close')"
      data-test="drawer-close"
      @click="$emit('cancel', $event)"
    >
      <VIcon
        size="24"
        icon="tabler-x"
      />
    </IconBtn>
  </div>
</template>
