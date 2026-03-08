<script setup lang="ts">
import type { JsonSchemaNode } from '@duxweb/dvha-core'
import type { TableColumn } from '@duxweb/dvha-naiveui'
import type { UseActionItem } from '@duxweb/dvha-pro'
import { DuxTablePage, useAction, useTableColumn } from '@duxweb/dvha-pro'
import { ref } from 'vue'

const path = 'ai/vector'
const action = useAction()
const column = useTableColumn()

const actions: UseActionItem[] = [
  {
    label: '新增向量库',
    color: 'primary',
    icon: 'i-tabler:plus',
    type: 'drawer',
    component: () => import('./form.vue'),
  },
]

const rowActions: UseActionItem[] = [
  {
    label: '编辑',
    type: 'drawer',
    component: () => import('./form.vue'),
  },
  {
    label: '删除',
    type: 'delete',
    path,
  },
]

const filter = ref<Record<string, any>>({})

const filterSchema: JsonSchemaNode[] = [
  {
    tag: 'n-input',
    name: 'keyword',
    attrs: {
      'placeholder': '请输入名称/标识',
      'v-model:value': [filter.value, 'keyword'],
    },
  },
  {
    tag: 'n-select',
    name: 'driver',
    attrs: {
      'placeholder': '选择驱动',
      'clearable': true,
      'options': [
        { label: 'FileVectorStore', value: 'file' },
        { label: 'MemoryVectorStore', value: 'memory' },
        { label: 'QdrantVectorStore', value: 'qdrant' },
        { label: 'ChromaVectorStore', value: 'chroma' },
      ],
      'v-model:value': [filter.value, 'driver'],
    },
  },
]

const columns: TableColumn[] = [
  {
    title: '名称',
    key: 'name',
    minWidth: 220,
    render: column.renderMedia({
      title: 'name',
      desc: 'code',
    }),
  },
  {
    title: '驱动',
    key: 'driver_name',
    minWidth: 180,
    render: (row: Record<string, any>) => row.driver_name || row.driver || '-',
  },
  {
    title: '说明',
    key: 'description',
    minWidth: 220,
    render: (row: Record<string, any>) => row.description || '-',
  },
  {
    title: '状态',
    key: 'active',
    width: 90,
    render: column.renderSwitch({
      key: 'active',
    }),
  },
  {
    title: '操作',
    key: 'action',
    align: 'center',
    width: 120,
    fixed: 'right',
    render: action.renderTable({
      align: 'center',
      type: 'button',
      text: true,
      items: rowActions,
    }),
  },
]
</script>

<template>
  <DuxTablePage
    :path="path"
    :columns="columns"
    :actions="actions"
    :filter="filter"
    :filter-schema="filterSchema"
  />
</template>
