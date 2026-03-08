<script setup lang="ts">
import type { JsonSchemaNode } from '@duxweb/dvha-core'
import type { DuxCardItemColor, DuxCardItemExtendItem, UseActionItem } from '@duxweb/dvha-pro'
import { useCustomMutation, useManage } from '@duxweb/dvha-core'
import { DuxCardItem, DuxCardPage, useAction } from '@duxweb/dvha-pro'
import { NSwitch, useMessage } from 'naive-ui'
import { ref } from 'vue'
import { useRouter } from 'vue-router'

const path = 'ai/agent'
const action = useAction()
const message = useMessage()
const { mutateAsync } = useCustomMutation()
const router = useRouter()

const actions: UseActionItem[] = [
  {
    label: '创建智能体',
    color: 'primary',
    icon: 'i-tabler:plus',
    type: 'link',
    path: 'ai/agent/create',
  },
]

const rowActions = [
  {
    label: '运行',
  },
  {
    label: '配置',
    type: 'link',
    path: 'ai/agent/edit',
  },
  {
    label: '删除',
    type: 'delete',
    path,
  },
]

const listRef = ref<any>(null)
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

const togglingMap = ref<Record<number, boolean>>({})
const activeOverrideMap = ref<Record<number, boolean>>({})

const isToggling = (id?: number) => !!(id && togglingMap.value[id])

async function toggleStatus(row: Record<string, any>, value: boolean) {
  const id = Number(row.id)
  if (!id)
    return

  const previousValue = id in activeOverrideMap.value ? activeOverrideMap.value[id] : Boolean(row.active)
  activeOverrideMap.value = { ...activeOverrideMap.value, [id]: value }
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
    listRef.value?.list?.onRefresh?.()
  }
  catch (error) {
    message.error((error as Error)?.message || '操作失败')
    activeOverrideMap.value = { ...activeOverrideMap.value, [id]: previousValue }
  }
  finally {
    const next = { ...togglingMap.value }
    delete next[id]
    togglingMap.value = next
  }
}

function resolveActive(row: Record<string, any>) {
  const id = Number(row.id)
  if (id && id in activeOverrideMap.value) {
    return activeOverrideMap.value[id]
  }
  return Boolean(row.active)
}

function handleAction(item: UseActionItem, row: Record<string, any>) {
  if (item.label === '运行') {
    handleGoChat(row)
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
  return resolveActive(row) ? 'primary' : 'neutral'
}

function getExtends(row: Record<string, any>): DuxCardItemExtendItem[] {
  return [
    {
      label: 'LLM 模型',
      value: row.model?.name || '未绑定',
    },
    {
      label: '更新时间',
      align: 'right',
      value: row.updated_at || '-',
    },
  ]
}

const manage = useManage()

function handleGoChat(row: Record<string, any>) {
  const code = row?.code
  if (!code) {
    message.warning('缺少智能体标识')
    return
  }
  router.push(manage.getRoutePath(`/ai/agent/chat/${encodeURIComponent(code)}`))
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
        icon="i-tabler:robot"
        :color="getCardColor(item)"
        :menu="getMenu(item)"
        :extends="getExtends(item)"
      >
        <template #action>
          <NSwitch
            :value="resolveActive(item)"
            size="small"
            :loading="isToggling(item.id)"
            @update:value="value => toggleStatus(item, value)"
          />
        </template>
        <template #default>
          <div class="text-sm text-muted line-clamp-2 min-h-40px">
            {{ item.description || item.instructions || '暂无说明' }}
          </div>
        </template>
      </DuxCardItem>
    </template>
  </DuxCardPage>
</template>
