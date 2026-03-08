<script setup lang="ts">
import type { JsonSchemaNode } from '@duxweb/dvha-core'
import type { DuxCardItemColor, DuxCardItemExtendItem, UseActionItem } from '@duxweb/dvha-pro'
import { useCustomMutation } from '@duxweb/dvha-core'
import { DuxCardItem, DuxCardPage, useAction } from '@duxweb/dvha-pro'
import { NAlert, NSwitch, useMessage } from 'naive-ui'
import { computed, ref } from 'vue'

const path = 'ai/token'
const action = useAction()
const message = useMessage()
const { mutateAsync } = useCustomMutation()
const togglingMap = ref<Record<number, boolean>>({})
const listRef = ref<any>(null)
const currentDomain = typeof window !== 'undefined' ? window.location.origin : ''
const agentApiBase = computed(() => `${currentDomain}/agent/v1`)

const actions: UseActionItem[] = [
  {
    label: '添加',
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
      placeholder: '请输入名称/Token',
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

const getCardColor = (row: Record<string, any>): DuxCardItemColor => {
  return row.active ? 'success' : 'neutral'
}

const getExtends = (row: Record<string, any>): DuxCardItemExtendItem[] => {
  return [
    {
      label: '可访问智能体',
      value: `${(row.models?.length ?? 0) || 0} 个`,
    },
    {
      label: '过期时间',
      align: 'right',
      value: row.expired_at || '长期有效',
    },
  ]
}

const isToggling = (id?: number) => !!(id && togglingMap.value[id])

const toggleStatus = async (row: Record<string, any>, value: boolean) => {
  const id = Number(row.id)
  if (!id)
    return

  togglingMap.value = { ...togglingMap.value, [id]: true }
  try {
    await mutateAsync({
      path: `${path}/${id}`,
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
</script>

<template>
  <DuxCardPage
    ref="listRef"
    :path="path"
    :actions="actions"
    :filter="filter"
    :filter-schema="filterSchema"
  >
    <template #header>
      <NAlert type="info">第三方系统 OpenAPI 调用地址：<span class="font-bold p-0.5">{{ agentApiBase }}</span></NAlert>
    </template>
    <template #default="{ item }">
      <DuxCardItem
        :title="item.name"
        :desc="item.token"
        icon="i-tabler:key"
        :color="getCardColor(item)"
        :menu="getMenu(item)"
        :extends="getExtends(item)"
      >
        <template #action>
          <div class="flex items-center">
            <NSwitch
              :value="Boolean(item.active)"
              size="small"
              :loading="isToggling(item.id)"
              @update:value="value => toggleStatus(item, value)"
            />
          </div>
        </template>
        <template #desc>
          <div class="text-xs text-muted truncate font-mono">
            {{ item.token }}
          </div>
        </template>
        <template #footer>
          <div class="text-xs text-muted">
            最后使用：{{ item.last_used_at || '未使用' }}
          </div>
        </template>
      </DuxCardItem>
    </template>
  </DuxCardPage>
</template>
