import { apiClient } from '@/api/client'

/**
 * Downloads a file from the authenticated API by fetching it as a Blob
 * and triggering a browser download with the specified filename.
 */
export async function downloadFileFromApi(endpoint: string, defaultFilename: string): Promise<void> {
  const response = await apiClient.get(endpoint, {
    responseType: 'blob'
  })
  unduhDariRespons(response, defaultFilename)
}

/** Seperti downloadFileFromApi, tetapi lewat POST (mis. unduhan yang meminta konfirmasi password). */
export async function unduhFileDenganPost(endpoint: string, data: unknown, defaultFilename: string): Promise<void> {
  const response = await apiClient.post(endpoint, data, {
    responseType: 'blob'
  })
  unduhDariRespons(response, defaultFilename)
}

function unduhDariRespons(response: { data: BlobPart; headers?: Record<string, unknown> }, defaultFilename: string): void {
  let filename = defaultFilename
  const disposition = response.headers?.['content-disposition'] as string | undefined
  if (disposition && typeof disposition === 'string') {
    const match = disposition.match(/filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/)
    if (match && match[1]) {
      filename = match[1].replace(/['"]/g, '').trim()
    }
  }

  const contentType = (response.headers?.['content-type'] as string | undefined) || 'application/octet-stream'
  const blob = new Blob([response.data], {
    type: contentType
  })
  unduhBlob(blob, filename)
}

/** Picu unduhan browser untuk Blob yang sudah ada di memori. */
export function unduhBlob(blob: Blob, filename: string): void {
  const objectUrl = window.URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = objectUrl
  link.download = filename
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
  window.URL.revokeObjectURL(objectUrl)
}
