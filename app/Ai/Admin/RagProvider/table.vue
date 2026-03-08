<script setup lang="ts">
import type { JsonSchemaNode } from '@duxweb/dvha-core'
import type { TableColumn } from '@duxweb/dvha-naiveui'
import type { UseActionItem } from '@duxweb/dvha-pro'
import { DuxTablePage, useAction, useTableColumn } from '@duxweb/dvha-pro'
import { ref } from 'vue'

const path = 'ai/ragProvider'
const action = useAction()
const column = useTableColumn()

const actions: UseActionItem[] = [
  {
    label: '新增配置',
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
  },
]

const filter = ref<Record<string, any>>({})

const filterSchema: JsonSchemaNode[] = [
  {
    tag: 'n-input',
    name: 'keyword',
    attrs: {
      'placeholder': '请输入名称或标识',
      'v-model:value': [filter.value, 'keyword'],
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
      desc: (row: Record<string, any>) => `标识：${row.code || '自动生成'}`,
    }),
  },
  {
    title: '向量库',
    key: 'vector',
    minWidth: 160,
    render: (row: Record<string, any>) => row.vector?.name || '-',
  },
  {
    title: '存储驱动',
    key: 'storage',
    minWidth: 180,
    render: (row: Record<string, any>) => row.storage?.title || row.storage?.name || '-',
  },
  {
    title: '说明',
    key: 'description',
    minWidth: 220,
    render: (row: Record<string, any>) => row.description || '-',
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

<style scoped></style>
