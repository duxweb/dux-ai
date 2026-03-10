<script setup lang="ts">
import { DuxFormItem, DuxModalForm } from '@duxweb/dvha-pro'
import { NAlert, NInput, NSwitch } from 'naive-ui'
import { ref } from 'vue'

const model = ref({
  source: '',
  overwrite: true,
})
</script>

<template>
  <DuxModalForm path="ai/skill/import" :data="model" label-placement="top">
    <NAlert type="info" class="mb-4">
      支持本地目录、SKILL.md 文件、GitHub 仓库/子目录地址、ZIP 地址和原始 SKILL.md 地址。导入只保存技能文件，不执行外部安装器。
    </NAlert>

    <DuxFormItem label="导入来源" required>
      <NInput
        v-model:value="model.source"
        type="textarea"
        :rows="4"
        placeholder="/path/to/skills&#10;https://github.com/vercel-labs/agent-skills&#10;https://github.com/owner/repo/tree/main/skills/demo"
      />
    </DuxFormItem>

    <DuxFormItem label="覆盖同名技能">
      <NSwitch v-model:value="model.overwrite" />
    </DuxFormItem>
  </DuxModalForm>
</template>
