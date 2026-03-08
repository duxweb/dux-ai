<script setup lang="ts">
import type { TableColumn } from '@duxweb/dvha-naiveui'
import { DuxDrawerPage, DuxTable, useAction, useTableColumn } from '@duxweb/dvha-pro'

const props = defineProps<{
  id?: number | string
  workflowId?: string
  name?: string
  code?: string
}>()

const query = new URLSearchParams()
if (props.id) {
  query.set('flow_id', String(props.id))
}
if (props.workflowId) {
  query.set('workflow_id', String(props.workflowId))
}
const dataPath = `ai/flowLog${query.toString() ? `?${query.toString()}` : ''}`

const action = useAction()
const column = useTableColumn()

const columns: TableColumn[] = [
  {
    title: '#',
    key: 'id',
    width: 80,
  },
  {
    title: '执行时间',
    key: 'created_at',
    minWidth: 160,
  },
  {
    title: 'workflow_id',
    key: 'workflow_id',
    minWidth: 220,
    ellipsis: { tooltip: true },
  },
  {
    title: '耗时 (s)',
    key: 'duration',
    width: 120,
    render: row => (row.duration ? Number(row.duration).toFixed(3) : '0.000'),
  },
  {
    title: 'Token',
    key: 'token_total',
    width: 120,
    render: row => (row.token_total ? Number(row.token_total).toFixed(0) : '0'),
  },
  {
    title: '结果说明',
    key: 'message',
    minWidth: 200,
    ellipsis: { tooltip: true },
  },
  {
    title: '状态',
    key: 'status',
    width: 100,
    render: column.renderStatus({
      key: 'status',
      maps: {
        success: { label: '成功', value: 1 },
        suspended: { label: '挂起', value: 2 },
        error: { label: '失败', value: 0 },
      },
    }),
  },
  {
    title: '操作',
    key: 'action',
    width: 100,
    fixed: 'right',
    align: 'center',
    render: action.renderTable({
      type: 'button',
      text: true,
      align: 'center',
      items: [
        {
          label: '详情',
          type: 'modal',
          component: () => import('../FlowLog/view.vue'),
          width: 900,
        },
      ],
    }),
  },
]
</script>

<template>
  <DuxDrawerPage :scrollbar="false">
    <div class="h-full p-4">
      <DuxTable
        class="h-full"
        flex-height
        :path="dataPath"
        :columns="columns"
      />
    </div>
  </DuxDrawerPage>
</template>

<style scoped></style>
