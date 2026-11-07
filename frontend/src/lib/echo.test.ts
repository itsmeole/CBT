import { describe, expect, it, vi } from 'vitest'
import { buatAuthorizer } from './echo'

describe('buatAuthorizer', () => {
  it('mengirim socket_id dan nama channel ke endpoint lalu meneruskan hasilnya', async () => {
    const kirim = vi.fn().mockResolvedValue({ data: { auth: 'kunci:tanda-tangan' } })
    const authorizer = buatAuthorizer('http://localhost:8000/broadcasting/auth', kirim)

    const hasil = await new Promise<{ error: Error | null; data: unknown }>((resolve) => {
      authorizer({ name: 'private-sesi.7' }).authorize('123.456', (error, data) => resolve({ error, data }))
    })

    expect(kirim).toHaveBeenCalledWith('http://localhost:8000/broadcasting/auth', {
      socket_id: '123.456',
      channel_name: 'private-sesi.7'
    })
    expect(hasil).toEqual({ error: null, data: { auth: 'kunci:tanda-tangan' } })
  })

  it('melaporkan error ke callback saat server menolak (mis. bukan pengawas sesi itu)', async () => {
    const kirim = vi.fn().mockRejectedValue(new Error('Anda tidak memiliki akses.'))
    const authorizer = buatAuthorizer('http://x/broadcasting/auth', kirim)

    const hasil = await new Promise<{ error: Error | null; data: unknown }>((resolve) => {
      authorizer({ name: 'private-sesi.9' }).authorize('1.2', (error, data) => resolve({ error, data }))
    })

    expect(hasil.error?.message).toBe('Anda tidak memiliki akses.')
    expect(hasil.data).toBeNull()
  })
})
