<script setup>
// W-CONTRACT-3 (ADR-649) — éditeur Tiptap pour les modèles de contrat. Même moteur que @core
// (Tiptap + StarterKit), mais expose `insertAtCursor` : une variable est insérée À LA POSITION DU
// CURSEUR (ou remplace la sélection), pas en fin de contenu. Aucun HTML à écrire.
import { useEditor, EditorContent } from '@tiptap/vue-3'
import { StarterKit } from '@tiptap/starter-kit'
import { Placeholder } from '@tiptap/extension-placeholder'

const props = defineProps({
  modelValue: { type: String, default: '' },
  placeholder: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue'])

const editor = useEditor({
  content: props.modelValue,
  extensions: [StarterKit, Placeholder.configure({ placeholder: props.placeholder })],
  onUpdate: () => emit('update:modelValue', editor.value.getHTML()),
})

watch(() => props.modelValue, val => {
  if (editor.value && editor.value.getHTML() !== val) {
    editor.value.commands.setContent(val, false)
  }
})

onBeforeUnmount(() => editor.value?.destroy())

// Insère une variable au curseur (remplace la sélection courante le cas échéant).
const insertAtCursor = text => {
  editor.value?.chain().focus().insertContent(` ${text} `).run()
}
defineExpose({ insertAtCursor, focus: () => editor.value?.chain().focus().run() })
</script>

<template>
  <div class="contract-editor">
    <div
      v-if="editor"
      class="d-flex gap-1 flex-wrap pa-2 border-b"
    >
      <VBtn
        icon="tabler-bold"
        size="x-small"
        :variant="editor.isActive('bold') ? 'tonal' : 'text'"
        @click="editor.chain().focus().toggleBold().run()"
      />
      <VBtn
        icon="tabler-italic"
        size="x-small"
        :variant="editor.isActive('italic') ? 'tonal' : 'text'"
        @click="editor.chain().focus().toggleItalic().run()"
      />
      <VBtn
        icon="tabler-h-1"
        size="x-small"
        :variant="editor.isActive('heading', { level: 1 }) ? 'tonal' : 'text'"
        @click="editor.chain().focus().toggleHeading({ level: 1 }).run()"
      />
      <VBtn
        icon="tabler-h-2"
        size="x-small"
        :variant="editor.isActive('heading', { level: 2 }) ? 'tonal' : 'text'"
        @click="editor.chain().focus().toggleHeading({ level: 2 }).run()"
      />
      <VBtn
        icon="tabler-list"
        size="x-small"
        :variant="editor.isActive('bulletList') ? 'tonal' : 'text'"
        @click="editor.chain().focus().toggleBulletList().run()"
      />
      <VBtn
        icon="tabler-list-numbers"
        size="x-small"
        :variant="editor.isActive('orderedList') ? 'tonal' : 'text'"
        @click="editor.chain().focus().toggleOrderedList().run()"
      />
    </div>
    <EditorContent
      :editor="editor"
      class="contract-editor__content pa-4"
    />
  </div>
</template>

<style lang="scss">
.contract-editor {
  border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
  border-radius: 6px;

  .border-b { border-block-end: 1px solid rgba(var(--v-border-color), var(--v-border-opacity)); }

  .ProseMirror {
    min-block-size: 220px;
    outline: none;

    p.is-editor-empty:first-child::before {
      content: attr(data-placeholder);
      float: inline-start;
      color: rgba(var(--v-theme-on-surface), var(--v-disabled-opacity));
      pointer-events: none;
      block-size: 0;
    }
  }
}
</style>
