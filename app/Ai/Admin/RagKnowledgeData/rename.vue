<script setup lang="ts">
import { DuxFormItem, DuxModalForm } from '@duxweb/dvha-pro'
import { NInput } from 'naive-ui'
import { ref } from 'vue'

const props = defineProps<{
  id: number
  name?: string | null
}>()

const model = ref({
  file_name: props.name || '',
})

</script>

<template>
  <DuxModalForm :id="props.id" path="ai/ragKnowledgeData" :data="model" label-placement="top">
    <DuxFormItem label="文件名称" required>
      <NInput v-model:value="model.file_name" placeholder="请输入文件名称" />
    </DuxFormItem>
  </DuxModalForm>
</template>

<style scoped></style>
