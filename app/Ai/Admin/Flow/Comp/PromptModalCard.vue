<script setup>
import { DuxAiEditor, DuxCodeEditor } from '@duxweb/dvha-pro'
import { NButton, NCard, NModal } from 'naive-ui'
import { computed, ref } from 'vue'

const props = defineProps({
  modelValue: { type: String, default: '' },
  title: { type: String, default: '提示词' },
  emptyText: { type: String, default: '未设置内容' },
  placeholder: { type: String, default: '请输入内容...' },
})

const emit = defineEmits(['update:modelValue'])

const showEditor = ref(false)
const draft = ref('')

const preview = computed(() => {
  const text = String(props.modelValue || '').trim()
  if (!text) {
    return props.emptyText
  }
  return text
})

function openEditor() {
  draft.value = String(props.modelValue || '')
  showEditor.value = true
}

function closeEditor() {
  showEditor.value = false
}

function saveEditor() {
  emit('update:modelValue', String(draft.value || ''))
  showEditor.value = false
}
</script>

<template>
  <NCard size="small" class="border border-muted/20" :segmented="{ content: true }">
    <template #header>
      <div class="flex items-center justify-between gap-2">
        <div class="text-sm font-medium">
          {{ title }}
        </div>
        <NButton size="small" type="primary" secondary @click="openEditor">
          编辑
        </NButton>
      </div>
    </template>
    <div class="text-sm text-muted leading-6 whitespace-pre-wrap break-words max-h-32 overflow-y-auto">
      {{ preview }}
    </div>
  </NCard>

  <NModal v-model:show="showEditor" :mask-closable="false">
    <NCard
      style="width: min(980px, calc(100vw - 32px));"
      :title="`编辑${title}`"
      :bordered="false"
      size="small"
    >
      <template #header-extra>
        <NButton quaternary circle size="small" @click="closeEditor">
          <template #icon>
            <i class="i-tabler:x size-4" />
          </template>
        </NButton>
      </template>
      <DuxAiEditor editor-type="markdown" v-model:value="draft" />
      <template #footer>
        <div class="flex justify-end">
          <NButton type="primary" @click="saveEditor">
            完成
          </NButton>
        </div>
      </template>
    </NCard>
  </NModal>
</template>
