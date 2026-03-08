<script setup>
import { DuxFormItem, DuxFormLayout } from '@duxweb/dvha-pro'
import { NDynamicInput, NInput, NInputNumber, NSwitch } from 'naive-ui'
import { computed } from 'vue'
import { createDefaultGlobalSettings, normalizeGlobalSettings } from './Lib/globalSetting.js'

const props = defineProps({
  value: {
    type: Object,
    default: null,
  },
  fallback: {
    type: Object,
    default: null,
  },
})

const emit = defineEmits(['patch'])

const resolved = computed(() => {
  const merged = {
    ...createDefaultGlobalSettings(),
    ...(props.fallback || {}),
    ...(props.value || {}),
  }
  return normalizeGlobalSettings(merged)
})

function updateField(patch = {}) {
  emit('patch', patch)
}

function createVariableItem() {
  return {
    name: '',
    description: '',
    value: '',
  }
}

function normalizeVariableItem(item = {}) {
  return {
    name: typeof item.name === 'string' ? item.name : '',
    description: typeof item.description === 'string' ? item.description : '',
    value: typeof item.value === 'string' ? item.value : '',
  }
}

const variablesProxy = computed({
  get: () => resolved.value.variables.map(item => ({ ...item })),
  set: (value) => {
    updateField({
      variables: Array.isArray(value) ? value.map(normalizeVariableItem) : [],
    })
  },
})

function updateVariableField(index, key, value) {
  const next = variablesProxy.value.slice()
  const parsed = typeof value === 'number' ? String(value) : (value ?? '')
  const current = next[index] || createVariableItem()
  next[index] = { ...current, [key]: parsed }
  variablesProxy.value = next
}
</script>

<template>
  <DuxFormLayout label-placement="top">
    <DuxFormItem label="流程名称" path="name">
      <NInput
        :value="resolved.name"
        placeholder="请输入流程名称"
        @update:value="value => updateField({ name: value })"
      />
    </DuxFormItem>
    <DuxFormItem label="流程标识" path="code">
      <NInput
        :value="resolved.code"
        placeholder="唯一英文标识"
        @update:value="value => updateField({ code: value })"
      />
    </DuxFormItem>
    <DuxFormItem label="流程描述" path="description">
      <NInput
        :value="resolved.description"
        type="textarea"
        :rows="3"
        placeholder="补充流程用途"
        @update:value="value => updateField({ description: value })"
      />
    </DuxFormItem>
    <DuxFormItem label="全局变量">
      <NDynamicInput
        v-model:value="variablesProxy"
        :on-create="createVariableItem"
        show-add-button
        class="w-full"
      >
        <template #default="{ value, index }">
          <div class="flex-1 grid grid-cols-2 gap-2">
            <NInput
              :value="value?.name || ''"
              placeholder="变量名"
              @update:value="val => updateVariableField(index, 'name', val)"
            />
            <NInput
              :value="value?.value || ''"
              placeholder="变量值"
              @update:value="val => updateVariableField(index, 'value', val)"
            />
          </div>
        </template>
      </NDynamicInput>
    </DuxFormItem>
    <DuxFormItem label="启用流程">
      <NSwitch
        :value="resolved.status"
        @update:value="value => updateField({ status: Boolean(value) })"
      />
    </DuxFormItem>
    <DuxFormItem label="调试日志">
      <NSwitch
        :value="resolved.debug"
        @update:value="value => updateField({ debug: Boolean(value) })"
      />
    </DuxFormItem>
    <DuxFormItem label="默认节点超时（ms）" description="0 表示不设置（由节点/能力自行决定）">
      <NInputNumber
        :value="resolved.timeout_ms"
        :min="0"
        :step="500"
        placeholder="例如：30000"
        @update:value="value => updateField({ timeout_ms: Number(value || 0) })"
      />
    </DuxFormItem>
    <DuxFormItem label="默认重试次数" description="节点失败时最多重试次数（含首次执行）">
      <NInputNumber
        :value="resolved.retry?.max_attempts || 1"
        :min="1"
        :max="10"
        placeholder="例如：2"
        @update:value="value => updateField({ retry: { max_attempts: Number(value || 1) } })"
      />
    </DuxFormItem>
  </DuxFormLayout>
</template>
