<script setup lang="ts">
import type { JsonSchemaNode } from '@duxweb/dvha-core'
import type { DuxCardItemColor, DuxCardItemExtendItem, UseActionItem } from '@duxweb/dvha-pro'
import { useCustomMutation } from '@duxweb/dvha-core'
import { DuxCardItem, DuxCardPage, useAction, useDialog } from '@duxweb/dvha-pro'
import { NSwitch, useMessage } from 'naive-ui'
import { ref } from 'vue'

const path = 'ai/ragKnowledge'
const action = useAction()
const dialog = useDialog()

const actions: UseActionItem[] = [
  {
    label: '新增文档库',
    color: 'primary',
    icon: 'i-tabler:plus',
    type: 'drawer',
    component: () => import('./form.vue'),
  },
]

const listRef = ref<any>(null)
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
    name: 'config_id',
    attrs: {
      'placeholder': '选择驱动',
      'clearable': true,
      'path': 'ai/ragProvider/options',
      'label-field': 'label',
      'value-field': 'value',
      'v-model:value': [filter.value, 'config_id'],
    },
  },
]

const rowActions: UseActionItem[] = [
  {
    label: '编辑',
    type: 'drawer',
    component: () => import('./form.vue'),
    componentProps: (row: any) => ({
      id: row.id,
    }),
  },
  {
    label: '清空',
    type: 'link',
  },
  {
    label: '删除',
    type: 'delete',
  },
]

const message = useMessage()
const { mutateAsync } = useCustomMutation()
const togglingMap = ref<Record<number, boolean>>({})

const isToggling = (id?: number) => !!(id && togglingMap.value[id])

async function toggleStatus(item: Record<string, any>, value: boolean) {
  const id = Number(item.id)
  if (!id)
    return

  togglingMap.value = { ...togglingMap.value, [id]: true }
  try {
    await mutateAsync({
      path: `ai/ragKnowledge/${id}`,
      method: 'PATCH',
      payload: {
        status: value ? 1 : 0,
      },
    })
    message.success(value ? '已启用' : '已禁用')
    listRef.value?.list?.onRefresh?.()
  }
  catch (error) {
    message.error((error as Error)?.message || '操作失败')
  }
  finally {
    const next = { ...togglingMap.value }
    delete next[id]
    togglingMap.value = next
  }
}

function handleAction(item: UseActionItem, row: Record<string, any>) {
  if (item.label === '清空') {
    void handleClear(row)
    return
  }
  action.target({
    id: row.id,
    data: row,
    item,
  })
}

function getMenu(row: Record<string, any>) {
  return rowActions.map((item, index) => ({
    label: item.label || '',
    key: `${index}`,
    onClick: () => handleAction(item, row),
  }))
}

function getCardColor(row: Record<string, any>): DuxCardItemColor {
  return row.status ? 'primary' : 'neutral'
}

function getExtends(row: Record<string, any>): DuxCardItemExtendItem[] {
  return [
    {
      label: '文档数量',
      value: String(Number(row.document_count ?? 0)),
    },
    {
      label: '驱动',
      align: 'right',
      value: row.config_name || '未配置',
    },
  ]
}

async function handleClear(row: Record<string, any>) {
  const id = Number(row.id)
  if (!id)
    return

  dialog.confirm({
    title: '清空知识库',
    content: `确认清空「${row.name || id}」的所有数据吗？该操作会删除向量库中的全部向量以及已上传的文件记录，但不会删除知识库本身。`,
  }).then(async () => {
    try {
      await mutateAsync({
        path: `ai/ragKnowledge/${id}/clear`,
        method: 'POST',
      })
      message.success('已清空')
      listRef.value?.list?.onRefresh?.()
    }
    catch (error) {
      message.error((error as Error)?.message || '清空失败')
      throw error
    }
  }).catch(() => {})
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
        :desc="`更新时间：${item.updated_at || '-'}`"
        icon="i-tabler:database-search"
        :color="getCardColor(item)"
        :menu="getMenu(item)"
        :extends="getExtends(item)"
      >
        <template #action>
          <NSwitch
            :value="item.status"
            size="small"
            :loading="isToggling(item.id)"
            @update:value="value => toggleStatus(item, value)"
          />
        </template>
        <template #default>
          <div class="text-sm text-muted truncate">
            {{ ((item.description || '').trim()) || '暂无说明' }}
          </div>
        </template>
      </DuxCardItem>
    </template>
  </DuxCardPage>
</template>

<style scoped></style>
