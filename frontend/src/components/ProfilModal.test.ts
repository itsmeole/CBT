import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

// Pemanggil asli (bukan vi.fn) agar penolakan promise tidak dianggap tak tertangani oleh pelacak mock.
const klien = vi.hoisted(() => ({
  panggilan: [] as unknown[][],
  jawab: (() => Promise.resolve({ data: {} })) as () => Promise<unknown>
}))
const put = {
  mockReset: () => {
    klien.panggilan.length = 0
  },
  mockResolvedValue: (v: unknown) => {
    klien.jawab = () => Promise.resolve(v)
  },
  mockImplementation: (f: () => Promise<unknown>) => {
    klien.jawab = f
  }
}

vi.mock('@/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/client')>()
  return {
    ...actual,
    apiClient: {
      put: (...argumen: unknown[]) => {
        klien.panggilan.push(argumen)
        return klien.jawab()
      }
    }
  }
})

import { ApiError } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import type { AuthUser } from '@/types/auth'
import ProfilModal from './ProfilModal.vue'

function siapkan(user: Partial<AuthUser> = {}) {
  setActivePinia(createPinia())
  const auth = useAuthStore()
  auth.setUser({ id: 1, username: 'budi', nama: 'Budi Santoso', role: 'guru', email: null, nip: '123', ...user })
  return mount(ProfilModal)
}

describe('ProfilModal', () => {
  beforeEach(() => put.mockReset())

  it('NIP hanya tampil untuk guru', () => {
    expect(siapkan().text()).toContain('NIP')
    expect(siapkan({ role: 'operator' }).text()).not.toContain('NIP')
  })

  it('password saat ini baru diminta setelah username atau password diganti', async () => {
    const w = siapkan()
    expect(w.text()).not.toContain('Password saat ini')

    await w.find('input[autocomplete="username"]').setValue('budi2')
    expect(w.text()).toContain('Password saat ini')

    await w.find('input[autocomplete="username"]').setValue('budi')
    expect(w.text()).not.toContain('Password saat ini')

    await w.find('input[autocomplete="new-password"]').setValue('rahasia123')
    expect(w.text()).toContain('Password saat ini')
    expect(w.text()).toContain('Ulangi password baru')
  })

  it('menyimpan lewat PUT /profil dan memperbarui data pengguna di aplikasi', async () => {
    put.mockResolvedValue({ data: { user: { id: 1, username: 'budi', nama: 'Budi S., S.Pd', role: 'guru' } } })
    const w = siapkan()
    await w.find('input[autocomplete="name"]').setValue('Budi S., S.Pd')
    await w.find('form').trigger('submit')
    await flushPromises()

    expect(klien.panggilan[0]).toEqual(['/profil', expect.objectContaining({ nama: 'Budi S., S.Pd', username: 'budi', nip: '123' })])
    expect(useAuthStore().user?.nama).toBe('Budi S., S.Pd')
    expect(w.emitted('saved')).toBeTruthy()
    await vi.waitFor(() => expect(w.emitted('close')).toBeTruthy())
  })

  it('menampilkan galat per kolom dan tetap terbuka saat validasi gagal', async () => {
    put.mockImplementation(() => Promise.reject(new ApiError('VALIDATION_ERROR', 'Data tidak valid.', 422, { username: ['Username sudah dipakai pengguna lain.'] })))
    const w = siapkan()
    await w.find('input[autocomplete="username"]').setValue('dipakai')
    await w.find('input[autocomplete="current-password"]').setValue('rahasia')
    await w.find('form').trigger('submit')
    await flushPromises()

    expect(w.text()).toContain('Username sudah dipakai pengguna lain.')
    expect(w.emitted('close')).toBeUndefined()
  })
})
