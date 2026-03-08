<script setup lang="ts">
import type { JsonSchemaNode } from '@duxweb/dvha-core'
import type { TableColumn } from '@duxweb/dvha-naiveui'
import { useCustomMutation } from '@duxweb/dvha-core'
import { DuxTablePage, useAction, useTableColumn } from '@duxweb/dvha-pro'
import { NTag, useMessage } from 'naive-ui'
import { h, ref } from 'vue'

const action = useAction()
const column = useTableColumn()
const request = useCustomMutation()
const message = useMessage()
const listRef = ref<any>(null)

const callbackTypeLabel = (value: string) => ({
  capability: '能力任务',
  video: '视频任务',
  flow: '流程任务',
}[value] || value || '-')

const callbackActionLabel = (value: string) => ({
  invoke: '调用',
  poll: '轮询',
}[value] || value || '-')

const sourceTypeLabel = (value: string) => ({
  agent: '智能体',
  flow: '流程',
  api: '接口',
}[value] || value || '-')

const retryTask = async (row: Record<string, any>) => {
  try {
    await request.mutateAsync({
      path: `ai/scheduler/${row.id}/retry`,
      method: 'POST',
    })
    message.success('已加入重试队列')
    listRef.value?.onRefresh?.()
  }
  catch (error) {
    message.error((error as Error)?.message || '重试失败')
  }
}

const cancelTask = async (row: Record<string, any>) => {
  try {
    await request.mutateAsync({
      path: `ai/scheduler/${row.id}/cancel`,
      method: 'POST',
    })
    message.success('已取消任务')
    listRef.value?.onRefresh?.()
  }
  catch (error) {
    message.error((error as Error)?.message || '取消失败')
  }
}

const canOperate = (row: Record<string, any>) => {
  const status = String(row?.status || '')
  return ['pending', 'running', 'retrying'].includes(status)
}

const columns: TableColumn[] = [
  { title: '#', key: 'id', width: 80 },
  {
    title: '回调',
    key: 'callback_code',
    minWidth: 220,
    render: column.renderMedia({
      title: row => `${callbackTypeLabel(String(row.callback_type || ''))} / ${row.callback_code || '-'}`,
      desc: row => callbackActionLabel(String(row?.callback_action || '')),
    }),
  },
  {
    title: '状态',
    key: 'status',
    width: 110,
    render: (row) => {
      const status = String(row?.status || '')
      const maps: Record<string, { label: string, type: 'default' | 'info' | 'success' | 'warning' | 'error' }> = {
        pending: { label: '待执行', type: 'warning' },
        running: { label: '执行中', type: 'info' },
        retrying: { label: '重试中', type: 'default' },
        success: { label: '成功', type: 'success' },
        failed: { label: '失败', type: 'error' },
        canceled: { label: '取消', type: 'default' },
      }
      const info = maps[status] || { label: status || '-', type: 'default' as const }
      return h(NTag, { bordered: false, size: 'small', type: info.type }, { default: () => info.label })
    },
  },
  { title: '执行时间', key: 'execute_at', minWidth: 160 },
  { title: '尝试次数', key: 'attempts', width: 100 },
  { title: '最大重试', key: 'max_attempts', width: 100 },
  {
    title: '来源',
    key: 'source_type',
    width: 110,
    render: row => sourceTypeLabel(String(row?.source_type || '')),
  },
  { title: '来源ID', key: 'source_id', width: 100 },
  {
    title: '错误',
    key: 'last_error',
    minWidth: 220,
    ellipsis: { tooltip: true },
    render: row => row?.last_error || '-',
  },
  {
    title: '操作',
    key: 'action',
    width: 220,
    fixed: 'right',
    align: 'center',
    render: action.renderTable({
      type: 'button',
      text: true,
      align: 'center',
      items: [
        {
          label: '详情',
          type: 'drawer',
          component: () => import('./view.vue'),
          width: 860,
        },
        {
          label: '重试',
          show: row => canOperate(row || {}),
          onClick: row => retryTask(row),
        },
        {
          label: '取消',
          show: row => canOperate(row || {}),
          onClick: row => cancelTask(row),
        },
      ],
    }),
  },
]

const tabs = [
  { label: '全部', value: 'all' },
  { label: '待执行', value: 'pending' },
  { label: '执行中', value: 'running' },
  { label: '重试中', value: 'retrying' },
  { label: '成功', value: 'success' },
  { label: '失败', value: 'failed' },
  { label: '取消', value: 'canceled' },
]

const filter = ref<Record<string, any>>({})
const filterSchema: JsonSchemaNode[] = [
  {
    tag: 'n-input',
    name: 'keyword',
    attrs: {
      placeholder: '搜索 dedupe_key / 回调编码 / 错误',
      clearable: true,
      'v-model:value': [filter.value, 'keyword'],
    },
  },
  {
    tag: 'n-select',
    name: 'callback_type',
    attrs: {
      placeholder: '回调类型',
      clearable: true,
      options: [
        { label: '能力任务', value: 'capability' },
        { label: '视频任务', value: 'video' },
        { label: '流程任务', value: 'flow' },
      ],
      'v-model:value': [filter.value, 'callback_type'],
    },
  },
  {
    tag: 'n-input',
    name: 'callback_code',
    attrs: {
      placeholder: '回调编码',
      clearable: true,
      'v-model:value': [filter.value, 'callback_code'],
    },
  },
]
</script>

<template>
  <DuxTablePage
    ref="listRef"
    path="ai/scheduler"
    :tabs="tabs"
    :filter="filter"
    :filter-schema="filterSchema"
    :columns="columns"
  />
</template>
