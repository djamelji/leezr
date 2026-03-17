<script setup>
const props = defineProps({
  helpfulCount: { type: Number, default: 0 },
  notHelpfulCount: { type: Number, default: 0 },
  userFeedback: { type: Object, default: null },
  isAuthenticated: { type: Boolean, default: false },
})

const emit = defineEmits(['submit'])

const state = ref(props.userFeedback ? 'submitted' : 'idle')
const showComment = ref(false)
const comment = ref('')
const selectedHelpful = ref(props.userFeedback?.helpful ?? null)

function submit(helpful) {
  selectedHelpful.value = helpful
  if (!helpful) {
    showComment.value = true

    return
  }
  emit('submit', { helpful, comment: null })
  state.value = 'submitted'
}

function submitWithComment() {
  emit('submit', { helpful: selectedHelpful.value, comment: comment.value || null })
  state.value = 'submitted'
  showComment.value = false
}

watch(() => props.userFeedback, val => {
  if (val) {
    state.value = 'submitted'
    selectedHelpful.value = val.helpful
  }
})
</script>

<template>
  <VCard
    flat
    border
    class="mt-6"
  >
    <VCardText class="text-center">
      <!-- Authenticated → feedback widget -->
      <template v-if="isAuthenticated">
        <template v-if="state === 'idle' && !showComment">
          <p class="text-body-1 mb-3">
            {{ $t('documentation.wasHelpful') }}
          </p>
          <div class="d-flex justify-center gap-x-4">
            <VBtn
              variant="tonal"
              color="success"
              @click="submit(true)"
            >
              <VIcon
                icon="tabler-thumb-up"
                class="me-2"
              />
              {{ $t('documentation.yes') }}
            </VBtn>
            <VBtn
              variant="tonal"
              color="error"
              @click="submit(false)"
            >
              <VIcon
                icon="tabler-thumb-down"
                class="me-2"
              />
              {{ $t('documentation.no') }}
            </VBtn>
          </div>
          <div
            v-if="helpfulCount + notHelpfulCount > 0"
            class="text-caption text-medium-emphasis mt-2"
          >
            {{ helpfulCount }} / {{ helpfulCount + notHelpfulCount }} {{ $t('documentation.foundHelpful') }}
          </div>
        </template>

        <template v-else-if="showComment">
          <p class="text-body-1 mb-3">
            {{ $t('documentation.tellUsMore') }}
          </p>
          <AppTextarea
            v-model="comment"
            :placeholder="$t('documentation.commentPlaceholder')"
            rows="3"
            class="mb-3"
          />
          <VBtn
            color="primary"
            @click="submitWithComment"
          >
            {{ $t('documentation.submitFeedback') }}
          </VBtn>
        </template>

        <template v-else>
          <VIcon
            icon="tabler-check"
            color="success"
            size="32"
            class="mb-2"
          />
          <p class="text-body-1">
            {{ $t('documentation.thankYou') }}
          </p>
        </template>
      </template>

      <!-- Not authenticated → login prompt -->
      <template v-else>
        <p class="text-body-1 mb-3">
          {{ $t('documentation.loginForFeedback') }}
        </p>
        <VBtn
          variant="tonal"
          color="primary"
          :to="{ name: 'login' }"
        >
          {{ $t('documentation.login') }}
        </VBtn>
      </template>
    </VCardText>
  </VCard>
</template>
