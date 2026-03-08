export function extractPartText(part) {
  if (!part || typeof part !== 'object')
    return ''

  const normalize = (value) => {
    if (typeof value === 'string')
      return value
    if (value && typeof value === 'object') {
      if (typeof value.text === 'string')
        return value.text
      if (typeof value.content === 'string')
        return value.content
      if (typeof value.value === 'string')
        return value.value
    }
    return ''
  }

  return normalize(part.text) || normalize(part.content) || normalize(part.value) || ''
}

export function stringifyContent(content) {
  if (typeof content === 'string')
    return content
  if (Array.isArray(content)) {
    return content.map((part) => {
      if (typeof part === 'string')
        return part
      if (part?.type === 'text')
        return extractPartText(part)
      return ''
    }).join('')
  }
  if (content && typeof content === 'object') {
    try {
      return JSON.stringify(content, null, 2)
    }
    catch {
      return String(content ?? '')
    }
  }
  return String(content ?? '')
}

export function normalizeMediaUrl(value) {
  if (typeof value === 'string')
    return value.trim()
  if (value && typeof value === 'object') {
    if (typeof value.url === 'string')
      return value.url.trim()
    if (typeof value.content === 'string')
      return value.content.trim()
  }
  return ''
}

export function normalizeMediaUrls(input) {
  if (!input)
    return []
  if (typeof input === 'string')
    return input.trim() ? [input.trim()] : []
  if (Array.isArray(input))
    return input.flatMap(item => normalizeMediaUrls(item))
  if (typeof input === 'object') {
    const values = ['url', 'image_url', 'image', 'video_url', 'video', 'output_url', 'download_url']
      .flatMap(key => normalizeMediaUrls(input[key]))
    return values
  }
  return []
}

export function tryParseStructuredFromText(text) {
  const trimmed = (text || '').trim()
  if (!trimmed.startsWith('{') || !trimmed.endsWith('}'))
    return null
  try {
    const obj = JSON.parse(trimmed)
    if (!obj || typeof obj !== 'object')
      return null
    const type = String(obj?.type || '').toLowerCase()
    if (type === 'card' && Array.isArray(obj.card)) {
      return {
        card: obj.card,
        images: [],
        videos: [],
        text: '',
      }
    }

    const images = normalizeMediaUrls(obj.images || obj.image || obj.image_url || obj?.data?.images || obj?.data?.image || obj?.data?.image_url)
    const videos = normalizeMediaUrls(obj.videos || obj.video || obj.video_url || obj.output_url || obj.download_url || obj?.data?.videos || obj?.data?.video || obj?.data?.video_url)
    if (type === 'image' || type === 'video' || images.length || videos.length) {
      return {
        card: null,
        images: Array.from(new Set(images)),
        videos: Array.from(new Set(videos)),
        text: String(obj.text || obj.summary || obj.message || '').trim(),
      }
    }
  }
  catch {}
  return null
}

export function stripMarkdownImages(text) {
  if (!text)
    return ''
  return text
    .replace(/!\[[^\]]*]\(([^)]+)\)/g, '')
    .replace(/\n{3,}/g, '\n\n')
    .trim()
}

export function uniqueByUrl(list) {
  const seen = new Set()
  return list.filter((item) => {
    const url = normalizeMediaUrl(item)
    if (!url || seen.has(url))
      return false
    seen.add(url)
    return true
  })
}
