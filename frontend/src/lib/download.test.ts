import { describe, it, expect, vi, beforeEach } from 'vitest'
import { downloadFileFromApi } from './download'
import { apiClient } from '@/api/client'

describe('downloadFileFromApi', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    window.URL.createObjectURL = vi.fn(() => 'blob:mock-url')
    window.URL.revokeObjectURL = vi.fn()
  })

  it('downloads file with provided default filename', async () => {
    const mockBlob = new Blob(['sample data'], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' })
    vi.spyOn(apiClient, 'get').mockResolvedValueOnce({
      data: mockBlob,
      headers: {
        'content-type': 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
      }
    } as any)

    await downloadFileFromApi('/templates/excel/siswa', 'template_siswa.xlsx')

    expect(apiClient.get).toHaveBeenCalledWith('/templates/excel/siswa', {
      responseType: 'blob'
    })
    expect(window.URL.createObjectURL).toHaveBeenCalled()
    expect(window.URL.revokeObjectURL).toHaveBeenCalledWith('blob:mock-url')
  })
})

