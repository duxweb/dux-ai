<script setup lang="ts">
import type { JsonSchemaNode } from '@duxweb/dvha-core'
import type { DuxCardItemColor, DuxCardItemExtendItem, UseActionItem } from '@duxweb/dvha-pro'
import { useCustomMutation } from '@duxweb/dvha-core'
import { DuxCardItem, DuxCardPage, useAction } from '@duxweb/dvha-pro'
import { NSwitch, useMessage } from 'naive-ui'
import { ref } from 'vue'

const path = 'ai/provider'
const action = useAction()
const message = useMessage()
const { mutateAsync } = useCustomMutation()
const togglingMap = ref<Record<number, boolean>>({})
const listRef = ref<any>(null)

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
    label: '编辑',
    type: 'drawer',
    component: () => import('./form.vue'),
  },
  {
    label: '删除',
    type: 'delete',
    path: 'ai/provider',
  },
]

const filter = ref<Record<string, any>>({})

const filterSchema: JsonSchemaNode[] = [
  {
    tag: 'n-input',
    name: 'keyword',
    attrs: {
      placeholder: '请输入名称或标识',
      'v-model:value': [filter.value, 'keyword'],
    },
  },
]

const handleAction = (item: UseActionItem, row: Record<string, any>) => {
  action.target({
    id: row.id,
    data: row,
    item,
  })
}

const getMenu = (row: Record<string, any>) => {
  return rowActions.map((item, index) => ({
    label: item.label || '',
    key: `${index}`,
    onClick: () => handleAction(item, row),
  }))
}

const isToggling = (id?: number) => !!(id && togglingMap.value[id])

const toggleStatus = async (row: Record<string, any>, value: boolean) => {
  const id = Number(row.id)
  if (!id)
    return

  togglingMap.value = { ...togglingMap.value, [id]: true }
  try {
    await mutateAsync({
      path: `ai/provider/${id}`,
      method: 'PATCH',
      payload: {
        active: value ? 1 : 0,
      },
    })
    message.success(value ? '已启用' : '已禁用')
    listRef.value?.onRefresh?.()
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

const maskApiKey = (key?: string | null) => {
  if (!key)
    return '-'
  const visible = key.slice(0, 8)
  return `${visible}${'*'.repeat(8)}`
}

const fallbackIcon = 'i-tabler:sparkles'

const getCardColor = (row: Record<string, any>): DuxCardItemColor => {
  return row.active ? 'primary' : 'neutral'
}

const getExtends = (row: Record<string, any>): DuxCardItemExtendItem[] => {
  return [
    {
      label: 'KEY',
      value: maskApiKey(row.api_key),
    },
    {
      label: '协议',
      align: 'right',
      value: row.protocol_name || row.protocol || '-',
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
        :icon="item.icon || fallbackIcon"
        :color="getCardColor(item)"
        :menu="getMenu(item)"
        :extends="getExtends(item)"
      >
        <template #action>
          <NSwitch
            :value="Boolean(item.active)"
            size="small"
            :loading="isToggling(item.id)"
            @update:value="value => toggleStatus(item, value)"
          />
        </template>
        <template #default>
          <div class="text-sm text-muted overflow-hidden text-ellipsis">
            {{ item.description || '暂无说明' }}
          </div>
        </template>
      </DuxCardItem>
    </template>
  </DuxCardPage>
</template>
