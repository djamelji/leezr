const PREVIEWABLE_MIMES = [
  'application/pdf',
  'image/jpeg',
  'image/png',
  'image/webp',
]

export function useDocumentHelpers() {
  const formatFileSize = bytes => {
    if (bytes < 1024)
      return `${bytes} B`
    if (bytes < 1024 * 1024)
      return `${(bytes / 1024).toFixed(1)} KB`

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
  }

  const isPreviewable = mime => PREVIEWABLE_MIMES.includes(mime)

  const viewerKind = mime => {
    if (mime === 'application/pdf')
      return 'pdf'
    if (mime?.startsWith('image/'))
      return 'image'

    return 'unsupported'
  }

  const fileFormatIcon = mime => {
    if (mime === 'application/pdf')
      return 'tabler-file-type-pdf'
    if (mime === 'image/png')
      return 'tabler-file-type-png'
    if (mime === 'image/jpeg')
      return 'tabler-file-type-jpg'
    if (mime?.startsWith('image/'))
      return 'tabler-photo'
    if (mime?.includes('word') || mime?.includes('docx'))
      return 'tabler-file-type-docx'

    return 'tabler-file'
  }

  const fileFormatLabel = mime => {
    if (mime === 'application/pdf')
      return 'PDF'
    if (mime === 'image/png')
      return 'PNG'
    if (mime === 'image/jpeg')
      return 'JPG'
    if (mime === 'image/webp')
      return 'WEBP'
    if (mime?.includes('word') || mime?.includes('docx'))
      return 'DOCX'

    return 'FILE'
  }

  return { formatFileSize, isPreviewable, viewerKind, fileFormatIcon, fileFormatLabel }
}
