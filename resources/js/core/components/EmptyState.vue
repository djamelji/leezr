<script setup>
defineProps({
  icon: { type: String, default: 'tabler-inbox' },
  title: { type: String, required: true },
  description: { type: String, default: '' },
  ctaLabel: { type: String, default: '' },
  ctaTo: { type: [String, Object], default: undefined },
  ctaColor: { type: String, default: 'primary' },
  illustration: { type: String, default: '' },
})
</script>

<template>
  <div class="text-center pa-8">
    <VImg
      v-if="illustration"
      :src="illustration"
      max-width="200"
      class="mx-auto mb-4"
    />
    <VAvatar
      v-else
      size="80"
      variant="tonal"
      :color="ctaColor"
      class="mb-4"
    >
      <VIcon
        :icon
        size="40"
      />
    </VAvatar>

    <h5 class="text-h5 mb-2">
      {{ title }}
    </h5>

    <p
      v-if="description"
      class="text-body-1 text-medium-emphasis mb-4"
    >
      {{ description }}
    </p>

    <VBtn
      v-if="ctaLabel"
      :color="ctaColor"
      :to="ctaTo"
      variant="elevated"
    >
      {{ ctaLabel }}
    </VBtn>

    <slot />
  </div>
</template>
