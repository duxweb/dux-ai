<script setup>
import { NCard, NTooltip } from 'naive-ui'
import { computed } from 'vue'
import FieldListPreview from './FieldListPreview.vue'

const props = defineProps({
  fields: { type: Array, default: () => [] },
  emptyText: { type: String, default: '未声明输出字段' },
  mode: { type: String, default: 'text' },
  source: { type: String, default: 'default' },
  outputRef: { type: String, default: '{{nodes.<节点ID>.output.xxx}}' },
  inputRef: { type: String, default: '{{input.xxx}}' },
})

const typeLabelMap = {
  text: '文本',
  string: '文本',
  number: '数字',
  integer: '整数',
  boolean: '布尔',
  date: '日期',
  json: 'JSON',
  object: '对象',
  array: '数组',
  image: '图片',
  images: '多图片',
  file: '文件',
  files: '多文件',
}

const previewRows = computed(() => {
  const list = Array.isArray(props.fields) ? props.fields : []
  return list.map((item) => {
    const name = String(item?.name || '').trim()
    const typeKey = String(item?.type || 'text').trim().toLowerCase()
    return {
      name: name || '-',
      label: String(item?.label || '').trim(),
      type: typeLabelMap[typeKey] || typeKey || '文本',
      typeKey,
      required: Boolean(item?.required),
    }
  })
})

const outputRefExample = computed(() => String(props.outputRef || '{{nodes.<节点ID>.output.xxx}}'))
const inputRefExample = computed(() => String(props.inputRef || '{{input.xxx}}'))
const sourceLabel = computed(() => props.source === 'schema' ? '结构化 Schema' : '默认输出')
</script>

<template>
  <NCard size="small" class="border border-muted/20" :segmented="{ content: true }">
    <template #header>
      <div class="flex items-center gap-2 text-sm font-medium">
        输出字段
        <NTooltip trigger="hover">
          <template #trigger>
            <div class="i-tabler:help-circle size-4 text-muted cursor-help" />
          </template>
          <div class="text-sm leading-5">
            <div>可引用：<code>{{ outputRefExample }}</code></div>
            <div>或：<code>{{ inputRefExample }}</code></div>
          </div>
        </NTooltip>
        <span class="text-xs text-muted font-normal">来源：{{ sourceLabel }}</span>
        <span v-if="previewRows.length" class="text-xs text-muted font-normal">{{ previewRows.length }} 个字段</span>
      </div>
    </template>
    <FieldListPreview :rows="previewRows" :empty-text="emptyText" />
  </NCard>
</template>
