<script setup lang="ts">
import type { JsonSchemaNode } from '@duxweb/dvha-core'
import type { TableColumn } from '@duxweb/dvha-naiveui'
import type { UseActionItem } from '@duxweb/dvha-pro'
import { DuxTablePage, useAction, useTableColumn } from '@duxweb/dvha-pro'
import { NProgress } from 'naive-ui'
import { h, ref } from 'vue'

const path = 'ai/model'
const action = useAction()
const column = useTableColumn()

const actions: UseActionItem[] = [
  {
    label: '添加',
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
    width: 900,
    component: () => import('./test.vue'),
  },
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
]

const tabs = [
  { label: '全部', value: 'all' },
  { label: '会话', value: 'chat' },
  { label: '向量', value: 'embedding' },
  { label: '图片', value: 'image' },
  { label: '视频', value: 'video' },
]

const columns: TableColumn[] = [
  {
    title: '模型',
    key: 'name',
    minWidth: 250,
    render: column.renderMedia({
      title: 'name',
      desc: 'code',
    }),
  },
  {
    title: '服务商',
    key: 'provider',
    minWidth: 250,
    render: column.renderMedia({
      title: 'provider',
      desc: 'model',
    }),
  },
  {
    title: '额度',
    key: 'quota_tokens',
    minWidth: 250,
    render: (row) => {
      const total = Number(row.quota_tokens || 0)
      if (total <= 0) {
        return '不限'
      }
      const remaining = Number(row.quota_remaining ?? 0)
      const percentage = Math.max(0, Math.min(100, (remaining / total) * 100))
      return h('div', { class: 'flex flex-col gap-1' }, [
        h(NProgress, {
          percentage,
          height: 3,
          showIndicator: false,
          processing: false,
          status: 'success',
        }),
        h('div', { class: 'text-xs text-muted flex items-center gap-1' }, [
          h('span', { class: 'text-default font-medium' }, `剩${remaining}`),
          h('span', null, `/ 共${total} tokens`),
        ]),
      ])
    },
  },
  {
    title: '类型',
    key: 'type',
    width: 110,
    render: column.renderStatus({
      key: 'type',
      maps: {
        info: { label: '会话', value: 'chat' },
        success: { label: '向量', value: 'embedding' },
        warning: { label: '图片', value: 'image' },
        error: { label: '视频', value: 'video' },
      },
    }),
  },
  {
    title: '启用',
    key: 'active',
    width: 80,
    render: column.renderSwitch({
      key: 'active',
    }),
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
    :tabs="tabs"
    :columns="columns"
    :actions="actions"
    :filter="filter"
    :filter-schema="filterSchema"
  />
</template>
