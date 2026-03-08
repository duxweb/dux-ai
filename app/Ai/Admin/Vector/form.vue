<script setup lang="ts">
import { useCustomMutation, useJsonSchema } from '@duxweb/dvha-core'
import { DuxSelect } from '@duxweb/dvha-naiveui'
import { DuxDrawerTabForm, DuxFormItem, DuxFormLayout } from '@duxweb/dvha-pro'
import { NInput, NInputNumber, NSwitch, NTabPane } from 'naive-ui'
import { computed, onMounted, ref, watch } from 'vue'

const props = defineProps<{ id?: number | string }>()

const request = useCustomMutation()

const model = ref<Record<string, any>>({
  name: undefined,
  code: undefined,
  driver: 'file',
  options: {},
  active: true,
  description: undefined,
})

const driverRegistry = ref<any[]>([])

async function loadDriverRegistry() {
  try {
    const res = await request.mutateAsync({ path: 'ai/vector/drivers', method: 'GET' })
    const list = res?.data ?? res
    driverRegistry.value = Array.isArray(list) ? list : []
  }
  catch {
    driverRegistry.value = []
  }
}

const selectedDriverMeta = computed(() => {
  const code = model.value.driver
  return driverRegistry.value.find(item => String(item?.value) === String(code)) || null
})

const driverFormSchema = computed(() => {
  const schema = selectedDriverMeta.value?.form_schema
  return Array.isArray(schema) ? schema : []
})

const { render: driverFormRender } = useJsonSchema({
  data: driverFormSchema,
  context: computed(() => ({ options: model.value.options })),
  components: { DuxFormItem, DuxSelect, NInput, NInputNumber, NSwitch },
})

watch(
  () => model.value.options,
  (value) => {
    if (!value || typeof value !== 'object' || Array.isArray(value)) {
      model.value.options = {}
    }
  },
  { immediate: true, deep: true },
)

onMounted(async () => {
  await loadDriverRegistry()
})
</script>

<template>
  <DuxDrawerTabForm
    :id="props.id"
    path="ai/vector"
    :data="model"
    default-tab="base"
    invalidate="ai/vector"
  >
    <NTabPane name="base" label="基本信息">
      <DuxFormLayout label-placement="top" class="pb-4">
        <DuxFormItem label="向量库名称" required>
          <NInput v-model:value="model.name" placeholder="请输入向量库名称" />
        </DuxFormItem>

        <DuxFormItem label="调用标识" tooltip="可选，不填将自动生成">
          <NInput v-model:value="model.code" placeholder="例如：default_vector" />
        </DuxFormItem>

        <DuxFormItem label="向量库驱动" required>
          <DuxSelect
            v-model:value="model.driver"
            path="ai/vector/drivers"
            label-field="label"
            value-field="value"
            placeholder="请选择驱动"
          />
        </DuxFormItem>

        <DuxFormItem label="说明">
          <NInput v-model:value="model.description" type="textarea" placeholder="备注信息" :rows="2" />
        </DuxFormItem>

        <DuxFormItem label="启用">
          <NSwitch v-model:value="model.active" />
        </DuxFormItem>
      </DuxFormLayout>
    </NTabPane>

    <NTabPane name="options" label="驱动参数">
      <DuxFormLayout label-placement="top" class="pb-4">
        <component :is="driverFormRender" />
      </DuxFormLayout>
    </NTabPane>
  </DuxDrawerTabForm>
</template>

<style scoped></style>
