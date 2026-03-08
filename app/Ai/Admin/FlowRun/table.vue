<script setup lang="ts">
import type { JsonSchemaNode } from '@duxweb/dvha-core'
import type { TableColumn } from '@duxweb/dvha-naiveui'
import { useCustomMutation, useManage } from '@duxweb/dvha-core'
import { DuxTablePage, useAction, useDrawer, useTableColumn } from '@duxweb/dvha-pro'
import { NTag, useMessage } from 'naive-ui'
import { h, ref } from 'vue'
import { useRouter } from 'vue-router'

const router = useRouter()
const drawer = useDrawer()
const manage = useManage()
const message = useMessage()
const request = useCustomMutation()

const filter = ref<Record<string, any>>({})
const filterSchema: JsonSchemaNode[] = [
  {
    tag: 'DuxSelect',
    name: 'flow_id',
    attrs: {
      path: 'ai/flow',
      labelField: 'name',
      valueField: 'id',
      clearable: true,
      placeholder: '选择工作流',
      'v-model:value': [filter.value, 'flow_id'],
    },
  },
]

const tabs = [
  { label: '全部', value: 'all' },
  { label: '运行中', value: 'running' },
  { label: '恢复中', value: 'resuming' },
  { label: '挂起', value: 'suspended' },
  { label: '成功', value: 'success' },
  { label: '失败', value: 'failed' },
  { label: '取消', value: 'canceled' },
]

const openLogs = async (row: Record<string, any>) => {
  try {
    const res = await request.mutateAsync({
      path: `ai/flowRun/${row.id}/context`,
      method: 'GET',
    })
    const logId = res?.data?.log_id
    if (!logId) {
      message.warning('当前任务暂无可查看的执行日志')
      return
    }
    drawer.show({
      title: `日志详情 #${logId}`,
      width: 900,
      component: () => import('../FlowLog/view.vue'),
      componentProps: { id: logId },
    })
  }
  catch (error: any) {
    message.error(error?.message || '打开日志详情失败')
  }
}

const openExecute = async (row: Record<string, any>) => {
  try {
    const res = await request.mutateAsync({
      path: `ai/flowRun/${row.id}/context`,
      method: 'GET',
    })
    const flowId = res?.data?.flow_id
    if (!flowId) {
      message.warning('未找到对应流程，可能该执行尚未产生日志')
      return
    }
    router.push({
      path: manage.getRoutePath(`/ai/flow/chat/${flowId}`),
      query: {
        run_id: String(row.id || ''),
      },
    })
  }
  catch (error: any) {
    message.error(error?.message || '打开执行页面失败')
  }
}

const action = useAction()
const column = useTableColumn()

const columns: TableColumn[] = [
  { title: '#', key: 'id', width: 80 },
  {
    title: '流程运行',
    key: 'workflow_id',
    minWidth: 260,
    render: column.renderMedia({
      title: row => row?.flow_name || `流程运行 #${row.id}`,
      desc: row => row?.workflow_id || '-',
    }),
  },
  {
    title: '状态',
    key: 'status',
    width: 110,
    render: (row) => {
      const status = String(row?.status || '')
      const maps: Record<string, { label: string, type: 'default' | 'info' | 'success' | 'warning' | 'error' }> = {
        running: { label: '运行中', type: 'info' },
        resuming: { label: '恢复中', type: 'info' },
        suspended: { label: '挂起', type: 'warning' },
        success: { label: '成功', type: 'success' },
        failed: { label: '失败', type: 'error' },
        canceled: { label: '取消', type: 'default' },
      }
      const info = maps[status] || { label: status || '-', type: 'default' as const }
      return h(NTag, { bordered: false, size: 'small', type: info.type }, { default: () => info.label })
    },
  },
  { title: '更新时间', key: 'updated_at', minWidth: 170 },
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
          label: '查看执行',
          type: 'callback',
          callback: (_, row) => openExecute(row || {}),
        },
        {
          label: '日志详情',
          type: 'callback',
          callback: (_, row) => openLogs(row || {}),
        },
      ],
    }),
  },
]
</script>

<template>
  <DuxTablePage
    path="ai/flowRun"
    :tabs="tabs"
    :filter="filter"
    :filter-schema="filterSchema"
    :columns="columns"
  />
</template>
