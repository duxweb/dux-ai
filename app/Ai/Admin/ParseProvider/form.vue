<script setup lang="ts">
import { useCustomMutation, useJsonSchema } from '@duxweb/dvha-core'
import { DuxSelect } from '@duxweb/dvha-naiveui'
import { DuxDrawerTabForm, DuxFormItem, DuxFormLayout } from '@duxweb/dvha-pro'
import { NButton, NInput, NInputNumber, NSwitch, NTabPane } from 'naive-ui'
import { computed, onMounted, ref, watch } from 'vue'

const props = defineProps<{
  id?: number | string
}>()

const request = useCustomMutation()

const model = ref<Record<string, any>>({
  name: undefined,
  code: undefined,
  provider: undefined,
  storage_id: undefined,
  description: undefined,
  config: {
    log_enabled: false,
  },
})

const providerRegistry = ref<any[]>([])

async function loadProviderRegistry() {
  try {
    const res = await request.mutateAsync({ path: 'ai/parseProvider/providers', method: 'GET' })
    const list = res?.data ?? res
    providerRegistry.value = Array.isArray(list) ? list : []
  }
  catch {
    providerRegistry.value = []
  }
}

const selectedProviderMeta = computed(() => {
  const code = model.value.provider
  return providerRegistry.value.find(item => String(item?.value) === String(code)) || null
})

const providerDescription = computed(() => {
  const value = selectedProviderMeta.value?.description
  return typeof value === 'string' ? value.trim() : ''
})

const providerRegisterUrl = computed(() => {
  const value = selectedProviderMeta.value?.register_url
  return typeof value === 'string' ? value.trim() : ''
})

const providerOpenUrl = computed(() => {
  const value = selectedProviderMeta.value?.open_url
  return typeof value === 'string' ? value.trim() : ''
})

const providerOpenLabel = computed(() => {
  const value = selectedProviderMeta.value?.open_label
  return typeof value === 'string' && value.trim() ? value.trim() : '开通'
})

const providerFormSchema = computed(() => {
  const schema = selectedProviderMeta.value?.form_schema
  return Array.isArray(schema) ? schema : []
})

function openProviderRegisterUrl() {
  if (!providerRegisterUrl.value) {
    return
  }
  window.open(providerRegisterUrl.value, '_blank', 'noopener,noreferrer')
}

function openProviderOpenUrl() {
  if (!providerOpenUrl.value) {
    return
  }
  window.open(providerOpenUrl.value, '_blank', 'noopener,noreferrer')
}

const { render: providerConfigRender } = useJsonSchema({
  data: providerFormSchema,
  context: computed(() => model.value),
  components: { DuxFormItem, DuxSelect, NInput, NInputNumber, NSwitch },
})

watch(
  () => model.value.config,
  (config) => {
    if (!config || typeof config !== 'object') {
      model.value.config = { log_enabled: false }
      return
    }
    if (typeof config.log_enabled !== 'boolean') {
      config.log_enabled = !!config.log_enabled
    }
    if (config.__storage_id !== model.value.storage_id) {
      config.__storage_id = model.value.storage_id
    }
  },
  { immediate: true, deep: true },
)

watch(
  () => model.value.config?.__storage_id,
  (value) => {
    if (value !== model.value.storage_id) {
      model.value.storage_id = value
    }
  },
)

onMounted(async () => {
  await loadProviderRegistry()
})
</script>

<template>
  <DuxDrawerTabForm
    :id="props.id"
    path="ai/parseProvider"
    :data="model"
    default-tab="base"
    invalidate="ai/parseProvider"
  >
    <NTabPane name="base" label="基本信息">
      <DuxFormLayout label-placement="top" class="pb-4">
        <DuxFormItem label="配置名称" required>
          <NInput v-model:value="model.name" placeholder="请输入配置名称" />
        </DuxFormItem>

        <DuxFormItem label="配置标识" tooltip="可选，不填将自动生成">
          <NInput v-model:value="model.code" placeholder="例如：moonshot_default" />
        </DuxFormItem>

        <DuxFormItem label="解析驱动" required tooltip="用于 PDF/图片 等非纯文本解析">
          <DuxSelect
            v-model:value="model.provider"
            path="ai/parseProvider/providers"
            label-field="label"
            value-field="value"
            placeholder="请选择服务商"
          />
        </DuxFormItem>

        <DuxFormItem label="说明">
          <NInput v-model:value="model.description" type="textarea" placeholder="描述该配置用途" :rows="3" />
        </DuxFormItem>
      </DuxFormLayout>
    </NTabPane>

    <NTabPane name="config" label="驱动配置">
      <DuxFormLayout label-placement="top" class="pb-4">
        <DuxFormItem v-if="providerDescription || providerRegisterUrl || providerOpenUrl" label="驱动信息">
          <div class="flex flex-col gap-2 rounded border border-primary/15 bg-primary/5 p-3">
            <div class="text-sm text-muted leading-6">
              {{ providerDescription || '该驱动暂未提供说明' }}
            </div>
            <div class="flex gap-2">
              <NButton v-if="providerOpenUrl" type="primary" @click="openProviderOpenUrl">
                {{ providerOpenLabel }}
              </NButton>
              <NButton v-if="providerRegisterUrl" type="primary" secondary @click="openProviderRegisterUrl">
                注册/文档
              </NButton>
            </div>
          </div>
        </DuxFormItem>

        <DuxFormItem label="日志开关" tooltip="开启后会输出解析过程日志（错误日志始终输出）">
          <NSwitch v-model:value="model.config.log_enabled" />
        </DuxFormItem>

        <component :is="providerConfigRender" />
      </DuxFormLayout>
    </NTabPane>
  </DuxDrawerTabForm>
</template>

<style scoped></style>
