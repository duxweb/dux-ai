<script setup lang="ts">
import type { JsonSchemaNode } from '@duxweb/dvha-core'
import type { TableColumn } from '@duxweb/dvha-naiveui'
import type { UseActionItem } from '@duxweb/dvha-pro'
import { DuxTablePage, useAction, useTableColumn } from '@duxweb/dvha-pro'
import { ref } from 'vue'

const path = 'ai/parseProvider'
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
    label: '运行',
    type: 'modal',
    width: 920,
    component: () => import('./run.vue'),
  },
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
  {
    tag: 'dux-select',
    name: 'provider',
    attrs: {
      'placeholder': '选择驱动',
      'clearable': true,
      'path': 'ai/parseProvider/providers',
      'label-field': 'label',
      'value-field': 'value',
      'v-model:value': [filter.value, 'provider'],
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
    key: 'provider_name',
    minWidth: 140,
  },
  {
    title: '操作',
    key: 'action',
    align: 'center',
    width: 140,
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
