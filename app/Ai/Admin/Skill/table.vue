<script setup lang="ts">
import type { JsonSchemaNode } from '@duxweb/dvha-core'
import type { TableColumn } from '@duxweb/dvha-naiveui'
import type { UseActionItem } from '@duxweb/dvha-pro'
import { DuxTablePage, useAction, useTableColumn } from '@duxweb/dvha-pro'
import { h, ref } from 'vue'

const path = 'ai/skill'
const action = useAction()
const column = useTableColumn()

const actions: UseActionItem[] = [
  {
    label: '导入',
    color: 'primary',
    icon: 'i-tabler:download',
    type: 'modal',
    width: 720,
    component: () => import('./import.vue'),
  },
  {
    label: '添加',
    color: 'default',
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
      placeholder: '请输入技能标识/标题/描述',
      'v-model:value': [filter.value, 'keyword'],
    },
  },
]

const tabs = [
  { label: '全部', value: 'all' },
  { label: '已启用', value: 'enabled' },
  { label: '部分兼容', value: 'partial' },
  { label: '手动维护', value: 'manual' },
]

const columns: TableColumn[] = [
  {
    title: '技能',
    key: 'name',
    minWidth: 260,
    render: column.renderMedia({
      title: 'title',
      desc: 'name',
    }),
  },
  {
    title: '描述',
    key: 'description',
    minWidth: 300,
    render: (row) => h('div', { class: 'line-clamp-2 text-sm' }, row.description || '-'),
  },
  {
    title: '来源',
    key: 'source_label',
    minWidth: 220,
    render: column.renderMedia({
      title: 'source_type_name',
      desc: 'source_label',
    }),
  },
  {
    title: '兼容',
    key: 'compatibility',
    width: 110,
    render: column.renderStatus({
      key: 'compatibility',
      maps: {
        success: { label: '完整兼容', value: 'full' },
        warning: { label: '部分兼容', value: 'partial' },
        info: { label: '手动处理', value: 'manual' },
      },
    }),
  },
  {
    title: '启用',
    key: 'enabled',
    width: 80,
    render: column.renderSwitch({
      key: 'enabled',
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
    :tabs="tabs"
    :columns="columns"
    :actions="actions"
    :filter="filter"
    :filter-schema="filterSchema"
  />
</template>
