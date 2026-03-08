<script setup lang="ts">
import { useCustomMutation } from '@duxweb/dvha-core'
import { DuxDrawerPage } from '@duxweb/dvha-pro'
import { NCode, NDescriptions, NDescriptionsItem, NSpin, useMessage } from 'naive-ui'
import { computed, onMounted, ref } from 'vue'

const props = defineProps<{ id: number }>()
const message = useMessage()
const request = useCustomMutation()
const loading = ref(false)
const detail = ref<Record<string, any> | null>(null)

const callbackTypeLabel = (value: string) => ({
  capability: '能力任务',
  video: '视频任务',
  flow: '流程任务',
}[value] || value || '-')

const callbackActionLabel = (value: string) => ({
  invoke: '调用',
  poll: '轮询',
}[value] || value || '-')

const sourceTypeLabel = (value: string) => ({
  agent: '智能体',
  flow: '流程',
  api: '接口',
}[value] || value || '-')

const loadDetail = async () => {
  loading.value = true
  try {
    const res = await request.mutateAsync({
      path: `ai/scheduler/${props.id}`,
      method: 'GET',
    })
    detail.value = res.data || null
  }
  catch (error) {
    message.error((error as Error)?.message || '加载详情失败')
  }
  finally {
    loading.value = false
  }
}

const callbackParamsText = computed(() => JSON.stringify(detail.value?.callback_params || {}, null, 2))
const resultText = computed(() => JSON.stringify(detail.value?.result || {}, null, 2))

onMounted(loadDetail)
</script>

<template>
  <DuxDrawerPage :scrollbar="false">
    <div class="h-full p-4">
      <NSpin :show="loading" class="h-full">
        <div class="space-y-4">
          <div class="rounded-lg border border-default p-4">
            <div class="mb-3 text-sm font-medium">基础信息</div>
            <NDescriptions label-placement="left" :column="2" size="small" bordered>
              <NDescriptionsItem label="ID">{{ detail?.id || '-' }}</NDescriptionsItem>
              <NDescriptionsItem label="状态">{{ detail?.status || '-' }}</NDescriptionsItem>
              <NDescriptionsItem label="回调类型">
                {{ callbackTypeLabel(String(detail?.callback_type || '')) }}
                <span class="text-muted"> ({{ detail?.callback_type || '-' }})</span>
              </NDescriptionsItem>
              <NDescriptionsItem label="回调编码">{{ detail?.callback_code || '-' }}</NDescriptionsItem>
              <NDescriptionsItem label="回调动作">
                {{ callbackActionLabel(String(detail?.callback_action || '')) }}
                <span class="text-muted"> ({{ detail?.callback_action || '-' }})</span>
              </NDescriptionsItem>
              <NDescriptionsItem label="执行时间">{{ detail?.execute_at || '-' }}</NDescriptionsItem>
              <NDescriptionsItem label="尝试次数">{{ detail?.attempts ?? '-' }}</NDescriptionsItem>
              <NDescriptionsItem label="最大重试">{{ detail?.max_attempts ?? '-' }}</NDescriptionsItem>
              <NDescriptionsItem label="来源类型">
                {{ sourceTypeLabel(String(detail?.source_type || '')) }}
                <span class="text-muted"> ({{ detail?.source_type || '-' }})</span>
              </NDescriptionsItem>
              <NDescriptionsItem label="来源ID">{{ detail?.source_id ?? '-' }}</NDescriptionsItem>
              <NDescriptionsItem label="去重Key" :span="2">{{ detail?.dedupe_key || '-' }}</NDescriptionsItem>
              <NDescriptionsItem label="最后错误" :span="2">{{ detail?.last_error || '-' }}</NDescriptionsItem>
            </NDescriptions>
          </div>

          <div class="rounded-lg border border-default p-4">
            <div class="mb-2 text-sm font-medium">回调参数</div>
            <NCode language="json" :code="callbackParamsText" />
          </div>

          <div class="rounded-lg border border-default p-4">
            <div class="mb-2 text-sm font-medium">执行结果</div>
            <NCode language="json" :code="resultText" />
          </div>

        </div>
      </NSpin>
    </div>
  </DuxDrawerPage>
</template>
