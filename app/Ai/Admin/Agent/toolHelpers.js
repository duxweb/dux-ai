import { schemaToTree, stringifyJson, toPlain, treeToSchema } from '../Lib/schemaTree.js'

export const schemaParamFields = [
  { key: 'required', label: '必填', component: 'switch', defaultValue: false },
  { key: 'default', label: '默认值', component: 'input', placeholder: '可选，字符串/数字/布尔/对象 JSON' },
]

export function createToolItem() {
  return {
    name: '',
    code: undefined,
    description: '',
    schema: { type: 'object', properties: {} },
    _settings: [],
    _schemaTree: [],
    schema_description: '',
  }
}

export const stringifySchema = stringifyJson

export { schemaToTree, toPlain, treeToSchema }

export function ensureToolSchema(tool) {
  if (!tool || typeof tool !== 'object')
    return
  if (!tool.schema || typeof tool.schema !== 'object')
    tool.schema = { type: 'object', properties: {} }
  if (!Array.isArray(tool._schemaTree) || !tool._schemaTree.length)
    tool._schemaTree = toPlain(schemaToTree(tool.schema))
  tool.schema_description = tool.schema?.description || tool.schema_description || ''
}

export function applyToolMeta(tool, registry, code) {
  tool.code = code || ''
  const meta = code ? registry?.[String(code)] || null : null
  if (!meta)
    return
  // 保留用户自定义名称/描述；若为空仅用 code 填充名称，不从注册信息覆盖
  if (!tool.name)
    tool.name = tool.code || ''
  if (!tool.label)
    tool.label = meta.label || meta.name || ''
  if (!tool.description)
    tool.description = meta.description || ''

  tool.schema = toPlain(meta.schema || { type: 'object', properties: {} })
  if (!tool.schema || typeof tool.schema !== 'object')
    tool.schema = { type: 'object', properties: {} }
  tool._schemaTree = toPlain(schemaToTree(tool.schema))
  tool.schema_description = tool.schema?.description || ''

  tool._settings = Array.isArray(meta.settings) ? meta.settings : []
  if (meta.defaults && typeof meta.defaults === 'object' && !Array.isArray(meta.defaults)) {
    Object.entries(meta.defaults).forEach(([key, val]) => {
      if (tool[key] === undefined || tool[key] === null || tool[key] === '')
        tool[key] = val
    })
  }
}

export function buildToolPayload(tool) {
  const payload = toPlain(tool || {})
  delete payload._settings
  delete payload._schemaTree
  if (!payload.schema || typeof payload.schema !== 'object')
    payload.schema = { type: 'object', properties: {} }
  payload.schema.type = 'object'
  if (payload.schema_description)
    payload.schema.description = payload.schema_description
  delete payload.schema_description
  return payload
}
