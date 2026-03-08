<script setup lang="ts">
import { DuxSelect } from '@duxweb/dvha-naiveui'
import { DuxDrawerTab, DuxFormItem, DuxFormLayout, DuxSchemaTreeEditor } from '@duxweb/dvha-pro'
import { NButton, NInput, NTabPane } from 'naive-ui'
import { computed, ref } from 'vue'
import SchemaTreeField from '../../Flow/Comp/SchemaTreeField.vue'
import { createKVItem, resolveSettingField } from '../../Lib/resolveSettingField.js'
import {
  applyToolMeta,
  createToolItem,
  ensureToolSchema,
  schemaParamFields as defaultSchemaParamFields,
  toPlain,
  treeToSchema,
} from '../toolHelpers.js'

const props = defineProps<{
  tool?: any
  toolRegistry: Record<string, any>
  schemaParamFields?: any[]
  onClose: () => void
  onConfirm: (val: any) => void
}>()

const draft = ref<any>(toPlain(props.tool || createToolItem()))
ensureToolSchema(draft.value)

const tool = computed(() => draft.value)
const schemaParamFields = computed(() => props.schemaParamFields || defaultSchemaParamFields)
const settingsFields = computed(() => {
  const fields = Array.isArray(tool.value?._settings) ? tool.value._settings : []
  return fields.filter((field: any) => {
    if (!field) {
      return false
    }
    if (field.component === 'field-config' || field.component === 'note') {
      return false
    }
    return true
  })
})

function handleToolSelect(code: string | number | null) {
  applyToolMeta(tool.value, props.toolRegistry, code)
}

function handleSchemaTreeChange(tree: any[]) {
  tool.value._schemaTree = Array.isArray(tree) ? toPlain(tree) : []
  tool.value.schema = treeToSchema(tool.value._schemaTree, tool.value.schema_description || tool.value.schema?.description || '')
}

function handleSchemaDescriptionChange(value: string) {
  tool.value.schema_description = value || ''
  if (!tool.value.schema || typeof tool.value.schema !== 'object') {
    tool.value.schema = { type: 'object' }
  }
  tool.value.schema = {
    ...tool.value.schema,
    description: tool.value.schema_description,
  }
}

function getStructuredSchemaTree(fieldName: string) {
  const value = tool.value?.[fieldName]
  return Array.isArray(value) ? value : []
}

function handleStructuredSchemaChange(fieldName: string, tree: any[]) {
  tool.value[fieldName] = Array.isArray(tree) ? toPlain(tree) : []
}

function handleClose() {
  props.onClose()
}

function handleConfirm() {
  props.onConfirm(toPlain(draft.value))
}
</script>

<template>
  <DuxDrawerTab default-tab="base" :on-close="handleClose">
    <NTabPane name="base" tab="基础信息">
      <DuxFormLayout label-placement="top">
        <DuxFormItem label="系统能力" required>
          <DuxSelect
            v-model:value="tool.code"
            path="ai/agent/tool"
            label-field="label"
            value-field="code"
            desc-field="description"
            placeholder="选择已注册的能力"
            @update:value="handleToolSelect"
          />
        </DuxFormItem>

        <DuxFormItem label="能力名" required>
          <NInput v-model:value="tool.name" placeholder="能力名（对 LLM 暴露的 name）" />
        </DuxFormItem>

        <DuxFormItem label="能力名称">
          <NInput v-model:value="tool.label" placeholder="能力中文名（用于 UI 展示）" />
        </DuxFormItem>

        <DuxFormItem label="能力描述" required>
          <NInput v-model:value="tool.description" type="textarea" placeholder="能力说明（可覆盖默认）" />
        </DuxFormItem>

        <DuxFormItem label="入参描述">
            <NInput
              v-model:value="tool.schema_description"
              type="textarea"
              :rows="2"
              placeholder="参数描述（写入 schema.description）"
              @update:value="handleSchemaDescriptionChange"
            />
          </DuxFormItem>
          
          <DuxFormItem label="入参字段" required>
            <SchemaTreeField
              :model-value="tool._schemaTree"
              @update:model-value="handleSchemaTreeChange"
            />
          </DuxFormItem>

      </DuxFormLayout>
    </NTabPane>

    <NTabPane name="config" tab="能力配置">
      <DuxFormLayout label-placement="top">
        <template v-for="field in settingsFields" :key="field.name">
          <div :class="field.name === 'structured_schema' ? 'col-span-2' : ''">
            <DuxFormItem :label="field.label" :required="!!field.required" :description="field.description">
              <DuxSchemaTreeEditor
                v-if="field.name === 'structured_schema'"
                :model-value="getStructuredSchemaTree(field.name)"
                :param-fields="schemaParamFields"
                @update:model-value="(tree) => handleStructuredSchemaChange(field.name, tree)"
              />
              <component
                :is="resolveSettingField(field, { createKVItem }).component"
                v-else
                v-model:value="tool[field.name]"
                v-bind="resolveSettingField(field, { createKVItem }).props"
              >
                <template v-if="field.component === 'kv-input'" #default="{ value: kv }">
                  <div class="flex gap-2 w-full">
                    <NInput v-model:value="kv.name" class="flex-1" :placeholder="field.componentProps?.namePlaceholder || 'Key'" />
                    <NInput v-model:value="kv.value" class="flex-1" :placeholder="field.componentProps?.valuePlaceholder || 'Value'" />
                  </div>
                </template>
              </component>
            </DuxFormItem>
          </div>
        </template>

        
      </DuxFormLayout>
    </NTabPane>

    <template #footer>
      <NButton @click="handleClose">
        取消
      </NButton>
      <NButton type="primary" @click="handleConfirm">
        保存
      </NButton>
    </template>
  </DuxDrawerTab>
</template>
