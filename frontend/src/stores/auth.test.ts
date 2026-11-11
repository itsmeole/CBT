import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

const { get, post, setAuthToken } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), setAuthToken: vi.fn() }))

vi.mock('@/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/client')>()
  return { ...actual, apiClient: { get, post }, setAuthToken }
})

import { bacaTokenTab } from '@/lib/sesiTab'
import { useAuthStore } from './auth'

const siswa = { id: 1, username: 'abc', nama: 'Siswa', role: 'siswa' }
const operator = { id: 2, username: 'op', nama: 'Operator', role: 'operator' }

describe('auth store: sesi bertahan saat refresh', () => {
  beforeEach(() => {
    sessionStorage.clear()
    setActivePinia(createPinia())
    get.mockReset()
    post.mockReset()
    setAuthToken.mockReset()
  })

  it('login menyimpan token di penyimpanan tab untuk semua peran', async () => {
    post.mockResolvedValueOnce({ data: { token: 'tok-siswa', user: siswa } })
    await useAuthStore().login('abc', 'rahasia')
    expect(bacaTokenTab()).toBe('tok-siswa')

    setActivePinia(createPinia())
    post.mockResolvedValueOnce({ data: { token: 'tok-staf', user: operator } })
    await useAuthStore().login('op', 'rahasia')
    expect(bacaTokenTab()).toBe('tok-staf')
  })

  it('setelah refresh, token siswa dipulihkan lalu /me memulihkan pengguna tanpa login ulang', async () => {
    sessionStorage.setItem('cbt_sesi', 'tok-siswa')
    get.mockResolvedValueOnce({ data: { user: siswa } })

    const auth = useAuthStore()
    await auth.fetchMe()

    expect(setAuthToken).toHaveBeenCalledWith('tok-siswa')
    expect(auth.user?.role).toBe('siswa')
    expect(auth.initialized).toBe(true)
  })

  it('setelah refresh, sesi staf juga pulih, dan logout menghapus token tab', async () => {
    sessionStorage.setItem('cbt_sesi', 'tok-staf')
    get.mockResolvedValueOnce({ data: { user: operator } })
    const auth = useAuthStore()
    await auth.fetchMe()
    expect(setAuthToken).toHaveBeenCalledWith('tok-staf')
    expect(auth.user?.role).toBe('operator')

    post.mockResolvedValueOnce({ data: {} })
    await auth.logout()
    expect(bacaTokenTab()).toBeNull()
  })

  it('tanpa token tersimpan, tidak ada token yang dipasang', async () => {
    get.mockRejectedValueOnce(new Error('401'))
    const auth = useAuthStore()
    await auth.fetchMe()
    expect(setAuthToken).not.toHaveBeenCalled()
    expect(auth.user).toBeNull()
  })
})
