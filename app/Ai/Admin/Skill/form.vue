<script setup lang="ts">
import { DuxDrawerTabForm, DuxFormItem, DuxFormLayout } from '@duxweb/dvha-pro'
import { NInput, NSwitch, NTabPane } from 'naive-ui'
import { ref } from 'vue'

const props = defineProps<{ id?: number | string }>()

const model = ref<Record<string, any>>({
  name: '',
  title: '',
  description: '',
  content: '',
  enabled: true,
})
</script>

<template>
  <DuxDrawerTabForm
    :id="props.id"
    path="ai/skill"
    :data="model"
    default-tab="base"
    invalidate="ai/skill"
  >
    <NTabPane name="base" label="基本信息">
      <DuxFormLayout label-placement="top" class="pb-4">
        <DuxFormItem label="技能标识" required>
          <NInput v-model:value="model.name" placeholder="如 skill-importer" />
        </DuxFormItem>
        <DuxFormItem label="技能标题">
          <NInput v-model:value="model.title" placeholder="用于后台展示，可留空" />
        </DuxFormItem>
        <DuxFormItem label="技能描述" required>
          <NInput v-model:value="model.description" type="textarea" :rows="3" placeholder="描述技能用途与适用场景" />
        </DuxFormItem>
        <DuxFormItem label="启用">
          <NSwitch v-model:value="model.enabled" />
        </DuxFormItem>
      </DuxFormLayout>
    </NTabPane>

    <NTabPane name="content" label="技能内容">
      <DuxFormLayout label-placement="top" class="pb-4">
        <DuxFormItem label="SKILL 内容" required description="正文会在智能体运行时注入提示词，支持 {baseDir} 占位符">
          <NInput
            v-model:value="model.content"
            type="textarea"
            :rows="18"
            placeholder="# Skill&#10;&#10;写明触发条件、步骤和注意事项"
          />
        </DuxFormItem>
      </DuxFormLayout>
    </NTabPane>
  </DuxDrawerTabForm>
</template>
