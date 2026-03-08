<script setup lang="ts">
import { DuxFormItem, DuxModalForm } from '@duxweb/dvha-pro'
import { NInput, NSwitch } from 'naive-ui'
import { ref } from 'vue'

const props = defineProps({
  id: {
    type: [String, Number],
    required: false,
  },
})

const model = ref({
  name: '',
  code: '',
  status: true,
  description: '',
})
</script>

<template>
  <DuxModalForm :id="props.id" path="ai/flow" :data="model" label-placement="top">
    <DuxFormItem label="流程名称" required>
      <NInput v-model:value="model.name" placeholder="请输入流程名称" />
    </DuxFormItem>
    <DuxFormItem label="唯一标识" description="可选，不填自动生成">
      <NInput v-model:value="model.code" placeholder="请输入标识（可留空）" />
    </DuxFormItem>
    <DuxFormItem label="启用">
      <NSwitch v-model:value="model.status" />
    </DuxFormItem>
    <DuxFormItem label="描述信息">
      <NInput v-model:value="model.description" type="textarea" placeholder="请输入描述" />
    </DuxFormItem>
  </DuxModalForm>
</template>

<style scoped></style>
