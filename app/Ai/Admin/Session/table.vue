<script setup lang="ts">
import type { JsonSchemaNode } from '@duxweb/dvha-core'
import type { TableColumn } from '@duxweb/dvha-naiveui'
import { DuxTablePage, useAction, useTableColumn } from '@duxweb/dvha-pro'
import { ref } from 'vue'

const action = useAction()
const column = useTableColumn()
const filter = ref<Record<string, any>>({})
const filterSchema: JsonSchemaNode[] = [
  {
    tag: 'n-input',
    name: 'keyword',
    attrs: {
      placeholder: '搜索标题 / 智能体 / 会话ID',
      'v-model:value': [filter.value, 'keyword'],
    },
  },
  {
    tag: 'n-input',
    name: 'user_type',
    attrs: {
      placeholder: '用户类型（如：admin）',
      'v-model:value': [filter.value, 'user_type'],
    },
  },
  {
    tag: 'n-input',
    name: 'user_id',
    attrs: {
      placeholder: '用户ID',
      'v-model:value': [filter.value, 'user_id'],
    },
  },
]

const columns: TableColumn[] = [
  { title: '#', key: 'id', width: 80 },
  {
    title: '会话',
    key: 'title',
    width: 240,
    render: column.renderMedia({
      title: row => row?.title || `会话 #${row?.id || '-'}`,
      desc: row => row?.agent_name || row?.agent_code || '-',
    }),
  },
  {
    title: 'Agent',
    key: 'agent_name',
    width: 120,
    render: row => row?.agent_name || row?.agent_code || '-',
  },
  { title: '用户类型', key: 'user_type', width: 110 },
  { title: '用户ID', key: 'user_id', width: 100 },
  { title: '最后消息时间', key: 'last_message_at', width: 170 },
  { title: '更新时间', key: 'updated_at', width: 170 },
  {
    title: '操作',
    key: 'action',
    width: 140,
    fixed: 'right',
    align: 'center',
    render: action.renderTable({
      type: 'button',
      text: true,
      align: 'center',
      items: [
        {
          label: '查看历史会话',
          title: '历史会话',
          type: 'modal',
          width: 960,
          component: () => import('./view.vue'),
          componentProps: row => ({
            agentCode: String(row?.agent_code || ''),
            sessionId: Number(row?.id || 0),
            title: row?.title || '',
          }),
          show: row => !!row?.agent_code && !!row?.id,
        },
      ],
    }),
  },
]
</script>

<template>
  <DuxTablePage
    path="ai/session"
    :filter="filter"
    :filter-schema="filterSchema"
    :columns="columns"
  />
</template>
