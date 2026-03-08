<script setup lang="ts">
import { useCustomMutation } from '@duxweb/dvha-core'
import { DuxSelect } from '@duxweb/dvha-naiveui'
import { DuxFormItem, DuxModalForm } from '@duxweb/dvha-pro'
import { NButton, NDatePicker, NInput, NSwitch, useMessage } from 'naive-ui'
import { onMounted, ref } from 'vue'

const props = defineProps<{
  id?: number | string
}>()

const message = useMessage()
const request = useCustomMutation()

const model = ref({
  name: '',
  token: '',
  active: true,
  expired_at: null as string | null,
  models: [] as number[],
})

const loadingToken = ref(false)

async function generateToken() {
  loadingToken.value = true
  try {
    const res = await request.mutateAsync({
      path: 'ai/token/generate',
      method: 'POST',
    })
    const token = res?.data?.token || res?.token
    if (token)
      model.value.token = token
  }
  catch (error: any) {
    message.error(error?.message || '生成 Token 失败')
  }
  finally {
    loadingToken.value = false
  }
}

onMounted(() => {
  if (!model.value.token) {
    generateToken()
  }
})
</script>

<template>
  <DuxModalForm :id="props.id" path="ai/token" :data="model" label-placement="top">
    <DuxFormItem label="名称" required>
      <NInput v-model:value="model.name" placeholder="请输入名称" />
    </DuxFormItem>
    <DuxFormItem label="Token" required>
      <div class="flex items-center gap-2">
        <NInput v-model:value="model.token" placeholder="sk-xxxx" />
        <NButton :loading="loadingToken" secondary type="primary" @click="generateToken">
          生成
        </NButton>
      </div>
    </DuxFormItem>
    <DuxFormItem label="允许访问的智能体">
      <DuxSelect
        v-model:value="model.models"
        path="ai/agent"
        label-field="name"
        value-field="id"
        placeholder="留空则默认全部"
        multiple
        clearable
      />
    </DuxFormItem>
    <DuxFormItem label="过期时间">
      <NDatePicker
        v-model:formatted-value="model.expired_at"
        type="datetime"
        value-format="yyyy-MM-dd HH:mm:ss"
        clearable
      />
    </DuxFormItem>
    <DuxFormItem label="启用">
      <NSwitch v-model:value="model.active" />
    </DuxFormItem>
  </DuxModalForm>
</template>
