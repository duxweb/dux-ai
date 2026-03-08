<script setup lang="ts">
import { DuxSchemaTreeEditor } from '@duxweb/dvha-pro'
import { NButton, NCard, NModal } from 'naive-ui'
import { computed, ref, watchEffect } from 'vue'
import { schemaToTree, toPlain } from '../../Lib/schemaTree.js'
import FieldListPreview from './FieldListPreview.vue'

const props = defineProps<{
  modelValue: any
  schema?: any
  fallbackTree?: any[]
  description?: string
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: any[]): void
}>()

const showEditor = ref(false)
const draft = ref<any[]>([])

const schemaParamFields: any[] = [
  { key: 'required', label: '必填', component: 'switch', defaultValue: false },
  { key: 'default', label: '值/模板', component: 'input', placeholder: '固定值或模板，例如 {{input.xxx}}' },
]

const value = computed<any[]>(() => Array.isArray(props.modelValue) ? props.modelValue : [])
const typeLabelMap: Record<string, string> = {
  string: '文本',
  number: '数字',
  integer: '整数',
  boolean: '布尔',
  object: '对象',
  array: '数组',
  json: 'JSON',
  image: '图片',
  file: '文件',
}

const flatFields = computed(() => {
  const rows: Array<{ name: string, type: string, typeKey: string, required: boolean }> = []
  const walk = (nodes: any[], prefix = '') => {
    if (!Array.isArray(nodes))
      return
    nodes.forEach((node) => {
      const name = String(node?.name || '').trim()
      if (!name)
        return
      const path = prefix ? `${prefix}.${name}` : name
      const typeKey = String(node?.type || 'string').trim().toLowerCase()
      rows.push({
        name: path,
        type: typeLabelMap[typeKey] || typeKey || '文本',
        typeKey,
        required: Boolean(node?.params?.required),
      })
      walk(Array.isArray(node?.children) ? node.children : [], path)
    })
  }
  walk(value.value)
  return rows
})

function openEditor() {
  draft.value = toPlain(value.value)
  if (!Array.isArray(draft.value))
    draft.value = []
  showEditor.value = true
}

function handleSave() {
  emit('update:modelValue', toPlain(draft.value))
  showEditor.value = false
}

watchEffect(() => {
  if (value.value.length > 0)
    return

  if (Array.isArray(props.fallbackTree) && props.fallbackTree.length > 0) {
    emit('update:modelValue', props.fallbackTree)
    return
  }

  if (props.schema && typeof props.schema === 'object') {
    const next = schemaToTree(props.schema)
    if (next.length > 0) {
      emit('update:modelValue', next)
    }
  }
})
</script>

<template>
  <NCard size="small" class="border border-muted/20" :segmented="{ content: true }">
    <template #header>
      <div class="flex items-center justify-between gap-2">
        <div class="flex items-center gap-2 text-sm font-medium">
          参数配置
          <span v-if="flatFields.length" class="text-xs text-muted font-normal">{{ flatFields.length }} 个字段</span>
        </div>
        <NButton size="small" type="primary" secondary @click="openEditor">
          配置
        </NButton>
      </div>
    </template>
    <FieldListPreview :rows="flatFields" empty-text="未配置参数" />
    <div v-if="description" class="text-xs text-muted mt-3 px-2.5 py-2 rounded-lg bg-muted/5 whitespace-pre-wrap leading-relaxed">
      {{ description }}
    </div>
  </NCard>

  <NModal v-model:show="showEditor">
    <NCard style="width: min(920px, calc(100vw - 32px));" title="配置 Schema" :bordered="false" size="small">
      <DuxSchemaTreeEditor v-model:model-value="draft" :param-fields="schemaParamFields" />
      <template #footer>
        <div class="flex justify-end gap-2">
          <NButton @click="showEditor = false">
            取消
          </NButton>
          <NButton type="primary" @click="handleSave">
            保存
          </NButton>
        </div>
      </template>
    </NCard>
  </NModal>
</template>
