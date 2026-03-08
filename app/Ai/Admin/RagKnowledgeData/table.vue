<script setup lang="ts">
import type { JsonSchemaNode } from '@duxweb/dvha-core'
import type { TableColumn } from '@duxweb/dvha-naiveui'
import type { UseActionItem } from '@duxweb/dvha-pro'
import { DuxTablePage, useAction, useTableColumn } from '@duxweb/dvha-pro'
import { h, ref } from 'vue'

const path = 'ai/ragKnowledgeData'
const action = useAction()
const column = useTableColumn()

const formatFileSize = (size?: number | string | null): string => {
  const value = Number(size)
  if (!Number.isFinite(value) || value <= 0)
    return '-'

  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  let unitIndex = 0
  let current = value

  while (current >= 1024 && unitIndex < units.length - 1) {
    current /= 1024
    unitIndex += 1
  }

  return `${current.toFixed(current >= 100 ? 0 : current >= 10 ? 1 : 2)} ${units[unitIndex]}`
}

const fileIconMap: Record<string, { icon: string; bg: string }> = {
  pdf: { icon: 'i-tabler:file-type-pdf', bg: 'bg-red-500' },
  doc: { icon: 'i-tabler:file-type-doc', bg: 'bg-blue-500' },
  docx: { icon: 'i-tabler:file-type-doc', bg: 'bg-blue-500' },
  xls: { icon: 'i-tabler:file-type-xls', bg: 'bg-green-500' },
  xlsx: { icon: 'i-tabler:file-type-xls', bg: 'bg-green-500' },
  csv: { icon: 'i-tabler:file-type-csv', bg: 'bg-emerald-500' },
  ppt: { icon: 'i-tabler:file-type-ppt', bg: 'bg-orange-500' },
  pptx: { icon: 'i-tabler:file-type-ppt', bg: 'bg-orange-500' },
  md: { icon: 'i-tabler:markdown', bg: 'bg-slate-500' },
  txt: { icon: 'i-tabler:file-type-txt', bg: 'bg-gray-500' },
  jpg: { icon: 'i-tabler:photo', bg: 'bg-fuchsia-500' },
  jpeg: { icon: 'i-tabler:photo', bg: 'bg-fuchsia-500' },
  png: { icon: 'i-tabler:photo', bg: 'bg-purple-500' },
  sheet: { icon: 'i-tabler:table', bg: 'bg-teal-500' },
  qa: { icon: 'i-tabler:message-circle-2', bg: 'bg-indigo-500' },
  document: { icon: 'i-tabler:file-description', bg: 'bg-sky-500' },
}

const resolveFileIcon = (row: any) => {
  const type = (row.type || '').toString().toLowerCase()
  if (type && fileIconMap[type])
    return fileIconMap[type]

  const fileType = (row.file_type || '').toString().toLowerCase()
  if (fileType && fileIconMap[fileType])
    return fileIconMap[fileType]

  const name = (row.file_name || '').toString()
  const ext = name.includes('.') ? name.split('.').pop()?.toLowerCase() : ''
  if (ext && fileIconMap[ext])
    return fileIconMap[ext]

  return { icon: 'i-tabler:file-description', bg: 'bg-slate-500' }
}

const columns: TableColumn[] = [
  {
    title: '文件',
    key: 'document',
    minWidth: 240,
    render: row => {
      const meta = resolveFileIcon(row)
      return h('div', { class: 'flex items-center gap-3' }, [
        h('div', { class: `flex size-8 items-center justify-center rounded text-white ${meta.bg}` }, [
          h('i', { class: `${meta.icon} size-4` }),
        ]),
        h('div', { class: 'min-w-0 truncate' }, row.file_name),
      ])
    },
  },
  {
    title: '文档类型',
    key: 'type_name',
    width: 120,
    render: row => row.type_name || '-',
  },
  {
    title: '文件大小',
    key: 'file_size',
    width: 140,
    render: row => formatFileSize(row.file_size),
  },
  {
    title: '文档库',
    key: 'knowledge_name',
    width: 120,
  },
  {
    title: '操作',
    key: 'action',
    align: 'center',
    width: 200,
    fixed: 'right',
    render: action.renderTable({
      type: 'button',
      text: true,
      align: 'center',
      items: [
        {
          label: '查看',
          type: 'callback',
          callback: (id, row: any) => {
            if (row.url) {
              window.open(row.url as string, '_blank', 'noopener,noreferrer')
            }
          },
        },
        {
          label: '编辑',
          type: 'modal',
          component: () => import('./rename.vue'),
          width: 480,
          componentProps: (row: any) => ({
            id: row.id,
            name: row.file_name,
          }),
        },
        {
          label: '删除',
          type: 'delete',
        },
      ],
    }),
  },
]

const actions: UseActionItem[] = [
  {
    label: '文件导入',
    color: 'primary',
    icon: 'i-tabler:upload',
    type: 'link',
    path: 'ai/ragImport',
  },
]

const filter = ref<Record<string, any>>({})

const filterSchema: JsonSchemaNode[] = [
  {
    tag: 'dux-select',
    name: 'knowledge_id',
    attrs: {
      placeholder: '选择文档库',
      clearable: true,
      path: 'ai/ragKnowledge',
      'label-field': 'name',
      'value-field': 'id',
      'v-model:value': [filter.value, 'knowledge_id'],
    },
  },
  {
    tag: 'n-input',
    name: 'keyword',
    attrs: {
      placeholder: '搜索文件名或链接',
      'v-model:value': [filter.value, 'keyword'],
    },
  },
]

const tabs = [
  { label: '全部', value: 'all' },
  { label: '文档', value: 'document' },
  { label: '问答', value: 'qa' },
  { label: '表格', value: 'sheet' },
]
</script>

<template>
  <DuxTablePage
    :path="path"
    :tabs="tabs"
    :columns="columns"
    :actions="actions"
    :filter="filter"
    :filter-schema="filterSchema"
  />
</template>

<style scoped></style>
