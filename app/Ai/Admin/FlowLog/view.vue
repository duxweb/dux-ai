<script setup lang="ts">
import { useOne } from '@duxweb/dvha-core'
import { DuxCodeEditor, DuxModalPage } from '@duxweb/dvha-pro'
import { NTag } from 'naive-ui'
import { computed } from 'vue'

const props = defineProps<{ id: number }>()

const { data: info } = useOne({
  path: 'ai/flowLog',
  id: props.id,
})

const logItems = computed(() => info.value?.data?.logs ?? [])

function resolveFlowStatusType(status: number) {
  if (status === 1)
    return 'success'
  if (status === 2)
    return 'warning'
  return 'error'
}

function resolveFlowStatusLabel(status: number) {
  if (status === 1)
    return '执行成功'
  if (status === 2)
    return '已挂起'
  return '执行失败'
}

function resolveLogStatusType(log: any) {
  const nodeStatus = typeof log?.meta?.node_status === 'number' ? log.meta.node_status : 1
  if (nodeStatus === 0)
    return 'error'
  if (nodeStatus === 2)
    return 'warning'
  return 'success'
}

function resolveLogStatusLabel(log: any) {
  const nodeStatus = typeof log?.meta?.node_status === 'number' ? log.meta.node_status : 1
  if (nodeStatus === 0)
    return '执行失败'
  if (nodeStatus === 2)
    return '已跳过'
  return '执行完成'
}

function resolveLogTitle(log: any, index: number) {
  return log?.meta?.label || log?.meta?.node || `节点 ${index + 1}`
}

function resolveLogType(log: any) {
  return log?.meta?.type || '-'
}

function resolveLogMessage(log: any) {
  const nodeMessage = log?.meta?.node_message
  if (typeof nodeMessage === 'string' && nodeMessage) {
    return nodeMessage
  }
  if (typeof log?.message === 'string' && log.message && log.message !== 'ok') {
    return log.message
  }
  return ''
}
</script>

<template>
  <DuxModalPage>
    <div class="space-y-4">
      <div class="grid grid-cols-2 lg:grid-cols-3 gap-4">
        <div class="p-3 bg-muted rounded">
          <div class="text-xs text-muted">
            流程
          </div>
          <div class="text-base font-medium">
            {{ info?.data?.flow_name || info?.data?.flow_code }}
          </div>
        </div>
        <div class="p-3 bg-muted rounded">
          <div class="text-xs text-muted">
            执行时间
          </div>
          <div class="text-base font-medium">
            {{ info?.data?.created_at }}
          </div>
        </div>
        <div class="p-3 bg-muted rounded">
          <div class="text-xs text-muted">
            状态
          </div>
          <div>
            <NTag :type="resolveFlowStatusType(Number(info?.data?.status ?? 0))" size="small">
              {{ resolveFlowStatusLabel(Number(info?.data?.status ?? 0)) }}
            </NTag>
          </div>
        </div>
        <div class="p-3 bg-muted rounded">
          <div class="text-xs text-muted">
            耗时
          </div>
          <div class="text-base font-medium">
            {{ info?.data?.duration ?? 0 }}s
          </div>
        </div>
        <div class="p-3 bg-muted rounded">
          <div class="text-xs text-muted">
            Token 消耗
          </div>
          <div class="text-base font-medium">
            {{ info?.data?.total_tokens ?? 0 }}
          </div>
        </div>
      </div>

      <div>
        <div class="font-medium mb-2">
          执行消息
        </div>
        <div class="p-3 bg-elevated rounded text-sm">
          {{ info?.data?.message || '-' }}
        </div>
      </div>

      <div>
        <div class="font-medium mb-2">
          模型用量
        </div>
        <div class="border border-muted rounded px-3 py-2 text-sm space-y-1">
          <div class="text-xs text-muted">
            prompt: {{ info?.data?.prompt_tokens ?? 0 }} / completion: {{ info?.data?.completion_tokens ?? 0 }} / total:
            {{ info?.data?.total_tokens ?? 0 }}
          </div>
        </div>
      </div>

      <div class="grid grid-cols-2 gap-4">
        <div>
          <div class="font-medium mb-2">
            输入参数
          </div>
          <DuxCodeEditor readonly :value="JSON.stringify(info?.data?.input ?? {}, null, 2)" />
        </div>
        <div>
          <div class="font-medium mb-2">
            输出结果
          </div>
          <DuxCodeEditor readonly :value="JSON.stringify(info?.data?.output ?? {}, null, 2)" />
        </div>
      </div>

      <div>
        <div class="font-medium mb-2">
          节点日志
        </div>
        <div class="space-y-3 max-h-96 overflow-auto">
          <div
            v-for="(log, index) in logItems"
            :key="log?.meta?.node || index"
            class="border border-muted rounded p-3 space-y-2"
          >
            <div class="flex items-center justify-between gap-2">
              <div class="text-sm font-medium text-default truncate">
                {{ resolveLogTitle(log, index) }}
              </div>
              <NTag size="small" :type="resolveLogStatusType(log)">
                {{ resolveLogStatusLabel(log) }}
              </NTag>
            </div>
            <div class="text-xs text-muted">
              类型：{{ resolveLogType(log) }}
            </div>
            <div v-if="resolveLogMessage(log)" class="text-xs text-muted">
              {{ resolveLogMessage(log) }}
            </div>
            <details class="bg-muted/40 rounded" :open="resolveLogStatusType(log) === 'error'">
              <summary class="cursor-pointer px-3 py-2 text-sm">
                原始日志
              </summary>
              <div class="px-2 pb-2">
                <DuxCodeEditor readonly :value="JSON.stringify(log ?? {}, null, 2)" />
              </div>
            </details>
          </div>
        </div>
      </div>
    </div>
  </DuxModalPage>
</template>

<style scoped></style>
