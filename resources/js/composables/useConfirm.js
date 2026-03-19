import { ref, h, defineComponent } from 'vue'
import ConfirmDialog from '@/components/dialogs/ConfirmDialog.vue'

export function useConfirm() {
  const isVisible = ref(false)
  const question = ref('')
  const confirmTitle = ref('')
  const confirmMsg = ref('')
  const cancelTitle = ref('')
  const cancelMsg = ref('')
  let resolvePromise = null

  function confirm(opts = {}) {
    question.value = opts.question || ''
    confirmTitle.value = opts.confirmTitle || ''
    confirmMsg.value = opts.confirmMsg || ''
    cancelTitle.value = opts.cancelTitle || ''
    cancelMsg.value = opts.cancelMsg || ''
    isVisible.value = true

    return new Promise(resolve => {
      resolvePromise = resolve
    })
  }

  function onConfirm(value) {
    isVisible.value = false
    if (resolvePromise) resolvePromise(value)
    resolvePromise = null
  }

  // Renderless component to drop in template
  const ConfirmDialogComponent = defineComponent({
    name: 'ConfirmDialogWrapper',
    setup() {
      return () => h(ConfirmDialog, {
        isDialogVisible: isVisible.value,
        confirmationQuestion: question.value,
        confirmTitle: confirmTitle.value,
        confirmMsg: confirmMsg.value,
        cancelTitle: cancelTitle.value,
        cancelMsg: cancelMsg.value,
        'onUpdate:isDialogVisible': val => { isVisible.value = val },
        onConfirm: onConfirm,
      })
    },
  })

  return {
    confirm,
    isVisible,
    question,
    confirmTitle,
    confirmMsg,
    cancelTitle,
    cancelMsg,
    onConfirm,
    ConfirmDialogComponent,
  }
}
