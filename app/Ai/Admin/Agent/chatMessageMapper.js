import { extractPartText, normalizeMediaUrl, stringifyContent, stripMarkdownImages, tryParseStructuredFromText, uniqueByUrl } from './chatMessageMedia'

export function mapOpenAiUiMessage(message) {
  const meta = {
    id: message?.id,
    created_at: message?.created_at,
  }

  let displayText = stringifyContent(message?.content)
  if (Array.isArray(message?.content)) {
    displayText = message.content.map((part) => {
      if (!part || typeof part !== 'object')
        return ''
      if (part.type === 'text')
        return extractPartText(part)
      if (part.type === 'image_url') {
        const image = part.image_url
        const url = normalizeMediaUrl(image)
        if (url) {
          meta.images = meta.images || []
          meta.images.push(typeof image === 'string' ? { url } : image)
        }
      }
      if (part.type === 'file_url') {
        const file = part.file || part.file_url || part
        const url = normalizeMediaUrl(file)
        if (url) {
          meta.files = meta.files || []
          meta.files.push(file)
        }
      }
      if (part.type === 'video_url') {
        const video = part.video_url
        const url = normalizeMediaUrl(video)
        if (url) {
          meta.videos = meta.videos || []
          meta.videos.push(typeof video === 'string' ? { url } : video)
        }
      }
      return ''
    }).join('')
  }

  if (message?.role === 'tool') {
    meta.tool = 'tool'
    meta.tool_call_id = message?.tool_call_id
  } else {
    meta.tool_calls = message?.tool_calls
  }

  if (message?.meta && typeof message.meta === 'object') {
    if (message?.role === 'assistant' && message.meta.approval && typeof message.meta.approval === 'object')
      meta.approval = message.meta.approval
    if (Array.isArray(message.meta.card))
      meta.card = message.meta.card
    if (Array.isArray(message.meta.images))
      meta.images = message.meta.images
    if (Array.isArray(message.meta.videos))
      meta.videos = message.meta.videos
    if (Array.isArray(message.meta.files))
      meta.files = message.meta.files
  }

  const structured = typeof displayText === 'string' ? tryParseStructuredFromText(displayText) : null
  if (structured) {
    if (Array.isArray(structured.card))
      meta.card = structured.card
    if (Array.isArray(structured.images) && structured.images.length > 0)
      meta.images = structured.images.map(url => ({ url }))
    if (Array.isArray(structured.videos) && structured.videos.length > 0)
      meta.videos = structured.videos.map(url => ({ url }))
    displayText = structured.text || ''
  }

  if (Array.isArray(meta.images) && meta.images.length > 0) {
    meta.images = uniqueByUrl(meta.images)
    displayText = stripMarkdownImages(displayText)
  }
  if (Array.isArray(meta.videos) && meta.videos.length > 0) {
    meta.videos = uniqueByUrl(meta.videos)
    if (!displayText)
      displayText = '视频消息'
  }

  return {
    role: message?.role || 'assistant',
    content: displayText,
    meta,
  }
}

export function mapOpenAiUiMessages(list, options = {}) {
  const rows = Array.isArray(list) ? list : []
  const mapped = rows.map(mapOpenAiUiMessage)
  if (options?.filterToolCallPlaceholder === false) {
    return mapped
  }
  return mapped.filter((msg) => {
    const isEmptyAssistant = msg.role === 'assistant' && (!msg.content || String(msg.content).trim() === '')
    const hasToolCalls = Array.isArray(msg.meta?.tool_calls)
    return !(isEmptyAssistant && hasToolCalls)
  })
}
