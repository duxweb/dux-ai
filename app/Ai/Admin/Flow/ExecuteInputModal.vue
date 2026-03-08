<script setup lang="ts">
import { DuxFileUpload, DuxFormItem, DuxFormLayout, DuxImageUpload, DuxModalPage } from '@duxweb/dvha-pro'
import { NButton, NDatePicker, NInput, NInputNumber, NSwitch } from 'naive-ui'
import { reactive, ref, watch } from 'vue'

interface FlowField {
  name: string
  label: string
  type: string
  required?: boolean
  description?: string
  defaultValue?: any
}

const props = defineProps<{
  fields: FlowField[]
  formData?: Record<string, any>
  fallbackJson?: string
  hasInputFields?: boolean
  onClose: () => void
  onConfirm: (payload: { form: Record<string, any>, fallbackJson: string }) => void
}>()

const formModel = reactive<Record<string, any>>({})
const fallbackValue = ref(props.fallbackJson ?? '')

watch(
  () => props.fields,
  (fields) => {
    syncFormModel(fields, props.formData)
  },
  { immediate: true, deep: true },
)

watch(
  () => props.formData,
  (value) => {
    syncFormModel(props.fields, value)
  },
  { deep: true },
)

watch(
  () => props.fallbackJson,
  (value) => {
    fallbackValue.value = value ?? ''
  },
  { immediate: true },
)

function syncFormModel(fields?: FlowField[], source?: Record<string, any>) {
  Object.keys(formModel).forEach((key) => {
    delete formModel[key]
  })
  const initial = source && typeof source === 'object' ? source : {}
  ;(fields ?? []).forEach((field) => {
    if (!field?.name) {
      return
    }
    const existing = initial[field.name]
    formModel[field.name] = existing !== undefined ? existing : resolveFieldDefaultValue(field)
  })
}

function resolveDefaultValue(type?: string) {
  switch (type) {
    case 'number':
      return null
    case 'boolean':
      return false
    case 'image':
    case 'file':
      return ''
    case 'images':
    case 'files':
      return []
    case 'json':
    case 'text':
    case 'textarea':
    case 'date':
    default:
      return ''
  }
}

function resolveFieldDefaultValue(field: FlowField) {
  const value = field.defaultValue
  const isEmptyString = typeof value === 'string' && value === ''
  if (value === undefined || value === null || (isEmptyString && field.type !== 'text' && field.type !== 'textarea' && field.type !== 'json')) {
    return resolveDefaultValue(field.type)
  }

  switch (field.type) {
    case 'number': {
      const num = Number(value)
      return Number.isNaN(num) ? null : num
    }
    case 'boolean': {
      if (typeof value === 'boolean')
        return value
      if (typeof value === 'string')
        return value === 'true' || value === '1'
      return Boolean(value)
    }
    case 'images':
    case 'files':
      return Array.isArray(value) ? value : []
    case 'image':
    case 'file':
      return value || null
    default:
      return value
  }
}

function submit() {
  props.onConfirm({
    form: { ...formModel },
    fallbackJson: fallbackValue.value,
  })
}

function close() {
  props.onClose()
}
</script>

<template>
  <DuxModalPage @close="close">
    <div class="space-y-4 max-h-[60vh] overflow-y-auto">
      <template v-if="hasInputFields">
        <DuxFormLayout label-placement="top">
          <DuxFormItem
            v-for="field in fields"
            :key="field.name"
            :label="field.label"
            :description="field.description"
            :required="field.required"
          >
            <NInputNumber
              v-if="field.type === 'number'"
              v-model:value="formModel[field.name]"
              :placeholder="`请输入${field.label}`"
              class="w-full"
            />
            <NSwitch
              v-else-if="field.type === 'boolean'"
              v-model:value="formModel[field.name]"
            />
            <NDatePicker
              v-else-if="field.type === 'date'"
              v-model:value="formModel[field.name]"
              type="datetime"
              value-format="yyyy-MM-dd HH:mm:ss"
              :placeholder="`请选择${field.label}`"
              class="w-full"
            />
            <NInput
              v-else-if="field.type === 'json' || field.type === 'textarea'"
              v-model:value="formModel[field.name]"
              type="textarea"
              :autosize="{ minRows: 3, maxRows: 6 }"
              :placeholder="field.type === 'json' ? `请输入 ${field.label} 的 JSON` : `请输入${field.label}`"
            />
            <DuxImageUpload
              v-else-if="field.type === 'image' || field.type === 'images'"
              v-model:value="formModel[field.name]"
              :multiple="field.type === 'images'"
            />
            <DuxFileUpload
              v-else-if="field.type === 'file' || field.type === 'files'"
              v-model:value="formModel[field.name]"
              :multiple="field.type === 'files'"
              :max-size="10"
            />
            <NInput
              v-else
              v-model:value="formModel[field.name]"
              :placeholder="`请输入${field.label}`"
            />
          </DuxFormItem>
        </DuxFormLayout>
      </template>
      <template v-else>
        <div class="space-y-2">
          <NInput
            v-model:value="fallbackValue"
            type="textarea"
            :autosize="{ minRows: 4, maxRows: 6 }"
            placeholder="请输入流程 JSON，例如 {&quot;query&quot;:&quot;你好&quot;}"
          />
          <div class="text-xs text-muted">
            将按照该 JSON 作为流程输入。
          </div>
        </div>
      </template>
    </div>
    <template #footer>
      <NButton @click="close">
        取消
      </NButton>
      <NButton type="primary" @click="submit">
        运行
      </NButton>
    </template>
  </DuxModalPage>
</template>
