<script setup lang="ts">
import { DuxSelect } from '@duxweb/dvha-naiveui'
import { DuxDrawerTabForm, DuxFormItem, DuxFormLayout } from '@duxweb/dvha-pro'
import { NInput, NTabPane } from 'naive-ui'
import { ref } from 'vue'

const props = defineProps<{ id?: number | string }>()

const model = ref({
  name: undefined,
  code: undefined,
  storage_id: undefined,
  vector_id: undefined,
  embedding_model_id: undefined,
  description: undefined,
})
</script>

<template>
  <DuxDrawerTabForm
    :id="props.id"
    path="ai/ragProvider"
    :data="model"
    default-tab="base"
    invalidate="ai/ragProvider"
  >
    <NTabPane name="base" label="基本信息">
      <DuxFormLayout label-placement="top">
        <DuxFormItem label="配置名称" required>
          <NInput v-model:value="model.name" placeholder="请输入配置名称" />
        </DuxFormItem>
        <DuxFormItem label="配置标识" description="可选，不填将自动生成">
          <NInput v-model:value="model.code" placeholder="例如：default_knowledge" />
        </DuxFormItem>
        <DuxFormItem label="说明">
          <NInput v-model:value="model.description" type="textarea" placeholder="描述该配置用途" :rows="3" />
        </DuxFormItem>
      </DuxFormLayout>
    </NTabPane>

    <NTabPane name="binding" label="关联配置">
      <DuxFormLayout label-placement="top">
        <DuxFormItem label="存储驱动" required>
          <DuxSelect
            v-model:value="model.storage_id"
            path="system/storage"
            label-field="title"
            value-field="id"
            desc-field="type_name"
            placeholder="请选择用于存储上传文件的驱动"
          />
        </DuxFormItem>
        <DuxFormItem label="向量库" required>
          <DuxSelect
            v-model:value="model.vector_id"
            path="ai/vector"
            label-field="name"
            value-field="id"
            placeholder="请选择向量库"
          />
        </DuxFormItem>
        <DuxFormItem label="向量模型" required>
          <DuxSelect
            v-model:value="model.embedding_model_id"
            path="ai/model"
            :params="{tab: 'embedding'}"
            label-field="name"
            value-field="id"
            placeholder="请选择向量模型"
          />
        </DuxFormItem>
      </DuxFormLayout>
    </NTabPane>
  </DuxDrawerTabForm>
</template>

<style scoped></style>
