export const toPlain = (val) => {
  try {
    return JSON.parse(JSON.stringify(val))
  }
  catch {
    return val
  }
}

export const schemaToTree = (schema, keyPrefix = '') => {
  if (!schema || typeof schema !== 'object')
    return []

  const type = Array.isArray(schema.type) ? schema.type[0] : (schema.type || 'object')
  if (type !== 'object')
    return []

  const props = schema.properties || {}
  const requiredList = Array.isArray(schema.required) ? schema.required : []

  return Object.entries(props).map(([name, child]) => {
    const childTypeRaw = child?.type
    const childType = Array.isArray(childTypeRaw) ? (childTypeRaw[0] || 'string') : (childTypeRaw || 'string')
    return {
      id: `${keyPrefix}${name}-${Math.random().toString(36).slice(2, 8)}`,
      name,
      type: childType,
      description: child?.description || '',
      params: {
        required: requiredList.includes(name),
        default: child?.default,
      },
      children: childType === 'object' ? schemaToTree(child, `${keyPrefix}${name}.`) : [],
    }
  })
}

function normalizeSchemaType(type) {
  const t = Array.isArray(type) ? (type[0] || 'string') : (type || 'string')
  const normalized = String(t).toLowerCase()
  if (normalized === 'text')
    return 'string'
  if (normalized === 'json')
    return 'object'
  return normalized
}

export const treeToSchema = (tree, description = '') => {
  if (!Array.isArray(tree) || tree.length === 0) {
    return { type: 'object', properties: {} }
  }

  const normalizeChildren = (nodes) => {
    const properties = {}
    const required = []

    nodes.forEach((node) => {
      if (!node?.name)
        return
      const entry = {
        type: normalizeSchemaType(node.type || 'string'),
        description: node.description || '',
      }
      if (node?.params?.required)
        required.push(node.name)
      if (node?.params?.default !== undefined)
        entry.default = node.params.default
      if (entry.type === 'object' && Array.isArray(node.children) && node.children.length) {
        const child = normalizeChildren(node.children)
        entry.properties = child.properties
        if (child.required.length)
          entry.required = child.required
      }
      properties[node.name] = entry
    })

    return { properties, required }
  }

  const { properties, required } = normalizeChildren(tree)
  const schema = { type: 'object', properties }
  if (description)
    schema.description = description
  if (required.length)
    schema.required = required
  return schema
}

export const stringifyJson = (value) => {
  if (value === null || value === undefined)
    return ''
  if (typeof value === 'string')
    return value
  try {
    return JSON.stringify(value, null, 2)
  }
  catch {
    return ''
  }
}

