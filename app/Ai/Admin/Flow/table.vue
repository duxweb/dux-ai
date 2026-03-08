<script setup lang="ts">
import type { JsonSchemaNode } from '@duxweb/dvha-core'
import type { DuxCardItemColor, DuxCardItemExtendItem, UseActionItem } from '@duxweb/dvha-pro'
import { useManage } from '@duxweb/dvha-core'
import { DuxCardItem, DuxCardPage, useAction } from '@duxweb/dvha-pro'
import { ref } from 'vue'
import { useRouter } from 'vue-router'

const path = 'ai/flow'
const action = useAction()
const listRef = ref<any>(null)
const router = useRouter()
const manage = useManage()

const actions: UseActionItem[] = [
  {
    label: '创建流程',
    color: 'primary',
    icon: 'i-tabler:plus',
    type: 'modal',
    component: () => import('./form.vue'),
  },
]

const rowActions: UseActionItem[] = [
  {
    label: '编辑',
    type: 'modal',
    component: () => import('./form.vue'),
  },
  {
    label: '设计',
    type: 'link',
    path: 'ai/flow/edit',
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
      'placeholder': '请输入流程名称或标识',
      'v-model:value': [filter.value, 'keyword'],
    },
  },
]

function handleAction(item: UseActionItem, row: Record<string, any>) {
  if (item.label === '执行') {
    handleExecute(row)
    return
  }
  action.target({
    id: row.id,
    data: row,
    item,
  })
}

function getMenu(row: Record<string, any>) {
  return [
    {
      label: '执行',
      key: 'execute',
      onClick: () => handleExecute(row),
    },
    ...rowActions.map((item, index) => ({
      label: item.label || '',
      key: `${index}`,
      onClick: () => handleAction(item, row),
    })),
  ]
}

function handleExecute(row: Record<string, any>) {
  if (!row?.id)
    return
  router.push(manage.getRoutePath(`/ai/flow/chat/${row.id}`))
}

function getCardColor(_row: Record<string, any>): DuxCardItemColor {
  return 'primary'
}

function getExtends(row: Record<string, any>): DuxCardItemExtendItem[] {
  return [
    {
      label: '状态',
      value: row.status ? '启用' : '禁用',
    },
    {
      label: '更新时间',
      align: 'right',
      value: row.updated_at || '-',
    },
  ]
}
</script>

<template>
  <DuxCardPage
    ref="listRef"
    :path="path"
    :actions="actions"
    :filter="filter"
    :filter-schema="filterSchema"
  >
    <template #default="{ item }">
      <DuxCardItem
        :title="item.name"
        :desc="`标识：${item.code || '-'}`"
        icon="i-tabler:share-2"
        :color="getCardColor(item)"
        :menu="getMenu(item)"
        :extends="getExtends(item)"
      >
        <template #default>
          <div class="text-sm text-muted line-clamp-2 min-h-40px">
            {{ item.description || '暂无说明' }}
          </div>
        </template>
      </DuxCardItem>
    </template>
  </DuxCardPage>
</template>
