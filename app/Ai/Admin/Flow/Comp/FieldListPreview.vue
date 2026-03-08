<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{
  rows?: Array<{
    name?: string
    label?: string
    type?: string
    typeKey?: string
    required?: boolean
  }>
  emptyText?: string
}>()

const iconMap: Record<string, string> = {
  string: 'i-tabler:typography',
  text: 'i-tabler:typography',
  textarea: 'i-tabler:align-left',
  number: 'i-tabler:hash',
  integer: 'i-tabler:hash',
  boolean: 'i-tabler:toggle-left',
  object: 'i-tabler:braces',
  array: 'i-tabler:brackets',
  json: 'i-tabler:code',
  image: 'i-tabler:photo',
  images: 'i-tabler:photo',
  file: 'i-tabler:file',
  files: 'i-tabler:files',
}

const rows = computed(() => Array.isArray(props.rows) ? props.rows : [])

function resolveIcon(typeKey: string) {
  const key = String(typeKey || '').trim().toLowerCase()
  return iconMap[key] || 'i-tabler:point'
}
</script>

<template>
  <div v-if="rows.length" class="overflow-hidden text-sm flex flex-col gap-1">
    <div
      v-for="(item, idx) in rows"
      :key="`${item?.name || 'field'}-${idx}`"
      class="flex items-center gap-2.5"
    >
      <i class="text-sm flex-none text-muted" :class="resolveIcon(String(item?.typeKey || item?.type || ''))" />
      <div class="truncate flex-1 min-w-0">
        <div class="truncate text-sm text-default">
          {{ item?.name || '-' }}
        </div>
        <div v-if="item?.label" class="truncate text-xs text-muted">
          {{ item?.label }}
        </div>
      </div>
      <div class="truncate text-sm text-muted flex-none max-w-[120px]">
        {{ item?.type || '-' }}
      </div>
      <span
        class="inline-flex items-center px-1.5 py-0.5 text-sm flex-none"
        :class="item?.required ? 'text-error' : 'text-muted'"
      >
        {{ item?.required ? '必填' : '可选' }}
      </span>
    </div>
  </div>
  <div v-else class="flex flex-col items-center gap-2 py-4 text-muted">
    <i class="i-tabler:database-off text-2xl" />
    <span class="text-xs">{{ emptyText || '未配置字段' }}</span>
  </div>
</template>
