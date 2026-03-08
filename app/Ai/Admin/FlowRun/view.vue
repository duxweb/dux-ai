<script setup lang="ts">
import { useCustomMutation } from '@duxweb/dvha-core'
import { NCode, NSpin, NTag, useMessage } from 'naive-ui'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

const props = defineProps<{ id: number }>()
const message = useMessage()
const request = useCustomMutation()
const loading = ref(false)
const detail = ref<Record<string, any> | null>(null)
const pollTimer = ref<number | null>(null)

const statusInfo = computed(() => {
  const status = String(detail.value?.status || '')
  const maps: Record<string, { label: string, type: 'default' | 'info' | 'success' | 'warning' | 'error' }> = {
    running: { label: '运行中', type: 'info' },
    resuming: { label: '恢复中', type: 'info' },
    suspended: { label: '后台等待中', type: 'warning' },
    success: { label: '成功', type: 'success' },
    failed: { label: '失败', type: 'error' },
    canceled: { label: '取消', type: 'default' },
  }
  return maps[status] || { label: status || '-', type: 'default' as const }
})

const showBackgroundHint = computed(() => ['running', 'suspended', 'resuming'].includes(String(detail.value?.status || '')))

const loadDetail = async () => {
  loading.value = true
  try {
    const res = await request.mutateAsync({ path: `ai/flowRun/${props.id}`, method: 'GET' })
    detail.value = res.data || null
  }
  catch (error) {
    message.error((error as Error)?.message || '加载详情失败')
  }
  finally {
    loading.value = false
  }
}

const stopPolling = () => {
  if (pollTimer.value) {
    window.clearInterval(pollTimer.value)
    pollTimer.value = null
  }
}

const startPolling = () => {
  stopPolling()
  pollTimer.value = window.setInterval(() => {
    if (!showBackgroundHint.value) {
      stopPolling()
      return
    }
    loadDetail()
  }, 5000)
}

onMounted(async () => {
  await loadDetail()
  startPolling()
})

onBeforeUnmount(() => {
  stopPolling()
})

</script>

<template>
  <NSpin :show="loading">
    <div class="space-y-3">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-2">
          <NTag size="small" :bordered="false" :type="statusInfo.type">
            {{ statusInfo.label }}
          </NTag>
          <span class="text-xs text-muted">workflow_id: {{ detail?.workflow_id || '-' }}</span>
        </div>
      </div>
      <div v-if="showBackgroundHint" class="rounded-lg border border-warning/30 bg-warning/5 px-3 py-2 text-xs text-warning">
        流程已转后台执行，页面将每 5 秒自动刷新状态。
      </div>
      <NCode language="json" :code="JSON.stringify(detail, null, 2)" />
    </div>
  </NSpin>
</template>
