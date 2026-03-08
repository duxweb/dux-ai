<script setup>
import { useCustomMutation } from '@duxweb/dvha-core'
import { DuxDrawerTabForm, DuxFormItem, DuxFormLayout, DuxIconPicker } from '@duxweb/dvha-pro'
import { NDynamicInput, NInput, NInputNumber, NSelect, NSwitch, NTabPane } from 'naive-ui'
import { computed, onMounted, ref, watch } from 'vue'

const props = defineProps({
  id: {
    type: [String, Number],
    required: false,
  },
})

const model = ref({
  name: '',
  code: '',
  protocol: 'openai_like',
  api_key: '',
  base_url: 'https://api.openai.com/v1',
  organization: '',
  project: '',
  timeout: 30,
  icon: '',
  headers: [],
  query_params: [],
  active: true,
  description: '',
})
const request = useCustomMutation()
const protocolRegistry = ref([])

function onCreatePair() {
  return {
    name: '',
    value: '',
  }
}

function normalizePairsForEdit(value) {
  if (!value) {
    return []
  }
  if (Array.isArray(value)) {
    return value
      .map(item => ({
        name: String(item?.name || ''),
        value: String(item?.value ?? ''),
      }))
      .filter(item => item.name !== '')
  }
  if (typeof value === 'object') {
    return Object.entries(value).map(([name, v]) => ({ name, value: String(v ?? '') }))
  }
  return []
}

const protocolOptions = computed(() => protocolRegistry.value.map(item => ({
  label: item.label,
  value: item.value,
})))

const currentProtocolMeta = computed(() => {
  return protocolRegistry.value.find(item => item.value === model.value.protocol) || null
})

const protocolDescription = computed(() => {
  return currentProtocolMeta.value?.description || ''
})

const requiresApiKey = computed(() => {
  const value = currentProtocolMeta.value?.requires_api_key
  return value === undefined ? true : Boolean(value)
})

const baseUrlPlaceholder = computed(() => {
  return currentProtocolMeta.value?.default_base_url || '请输入接口地址'
})

async function loadProtocols() {
  const res = await request.mutateAsync({
    path: 'ai/provider/protocols',
    method: 'GET',
  })
  const list = res?.data || []
  protocolRegistry.value = Array.isArray(list) ? list : []
}

watch(
  () => model.value.headers,
  (value) => {
    if (!Array.isArray(value)) {
      model.value.headers = normalizePairsForEdit(value)
    }
  },
)

watch(
  () => model.value.query_params,
  (value) => {
    if (!Array.isArray(value)) {
      model.value.query_params = normalizePairsForEdit(value)
    }
  },
)

watch(
  () => model.value.protocol,
  (value, oldValue) => {
    const meta = protocolRegistry.value.find(item => item.value === value)
    if (!meta) {
      return
    }
    const oldMeta = protocolRegistry.value.find(item => item.value === oldValue)
    const oldDefault = oldMeta?.default_base_url || ''
    const currentBase = String(model.value.base_url || '')
    if (!currentBase || currentBase === oldDefault) {
      model.value.base_url = meta.default_base_url || ''
    }
  },
)

onMounted(async () => {
  await loadProtocols()
})
</script>

<template>
  <DuxDrawerTabForm
    :id="props.id"
    path="ai/provider"
    :data="model"
    default-tab="base"
    invalidate="ai/provider"
  >
    <NTabPane name="base" label="基本信息">
      <DuxFormLayout label-placement="top">
        <DuxFormItem label="服务商名称" required>
          <NInput v-model:value="model.name" placeholder="请输入名称" />
        </DuxFormItem>
        <DuxFormItem label="调用标识" description="可选，不填将自动生成">
          <NInput v-model:value="model.code" placeholder="请输入调用标识" />
        </DuxFormItem>
        <DuxFormItem label="协议" required>
          <NSelect
            v-model:value="model.protocol"
            :options="protocolOptions"
            label-field="label"
            value-field="value"
            placeholder="选择协议"
          />
        </DuxFormItem>
        <DuxFormItem v-if="protocolDescription" label="协议说明">
          <div class="text-sm text-muted">
            {{ protocolDescription }}
          </div>
        </DuxFormItem>
        <DuxFormItem label="API Key" :required="requiresApiKey" :description="requiresApiKey ? '协议要求填写 API Key' : '该协议可留空'">
          <NInput v-model:value="model.api_key" type="textarea" :placeholder="requiresApiKey ? '请输入 API Key' : '可留空'" />
        </DuxFormItem>
        <DuxFormItem label="接口地址" required>
          <NInput v-model:value="model.base_url" :placeholder="baseUrlPlaceholder" />
        </DuxFormItem>
        <DuxFormItem label="说明">
          <NInput v-model:value="model.description" type="textarea" placeholder="备注信息" />
        </DuxFormItem>
        <DuxFormItem label="启用">
          <NSwitch v-model:value="model.active" />
        </DuxFormItem>
      </DuxFormLayout>
    </NTabPane>

    <NTabPane name="request" label="附加信息">
      <DuxFormLayout label-placement="top">
        <DuxFormItem label="图标">
          <DuxIconPicker v-model:value="model.icon" />
        </DuxFormItem>
        <DuxFormItem label="Organization">
          <NInput v-model:value="model.organization" placeholder="可选" />
        </DuxFormItem>
        <DuxFormItem label="Project">
          <NInput v-model:value="model.project" placeholder="可选" />
        </DuxFormItem>
        <DuxFormItem label="请求超时 (秒)">
          <NInputNumber v-model:value="model.timeout" :min="0" :precision="0" placeholder="30" />
        </DuxFormItem>

        <DuxFormItem label="附加请求头">
          <NDynamicInput v-model:value="model.headers" :on-create="onCreatePair">
            <template #default="{ value }">
              <div class="flex gap-2 w-full">
                <NInput v-model:value="value.name" class="flex-1" placeholder="Header 名称" />
                <NInput v-model:value="value.value" class="flex-1" placeholder="Header 内容" />
              </div>
            </template>
          </NDynamicInput>
        </DuxFormItem>

        <DuxFormItem label="附加 Query">
          <NDynamicInput v-model:value="model.query_params" :on-create="onCreatePair">
            <template #default="{ value }">
              <div class="flex gap-2 w-full">
                <NInput v-model:value="value.name" class="flex-1" placeholder="参数名" />
                <NInput v-model:value="value.value" class="flex-1" placeholder="参数值" />
              </div>
            </template>
          </NDynamicInput>
        </DuxFormItem>

      </DuxFormLayout>
    </NTabPane>
  </DuxDrawerTabForm>
</template>
