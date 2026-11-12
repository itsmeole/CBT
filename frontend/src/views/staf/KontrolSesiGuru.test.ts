import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const { get, post } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))

vi.mock('@/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/client')>()
  return { ...actual, apiClient: { get, post } }
})
vi.mock('vue-router', () => ({ useRouter: () => ({ replace: vi.fn() }) }))
vi.mock('@/components/StafLayout.vue', async () => {
  const { defineComponent, h } = await import('vue')
  return { default: defineComponent({ render() { return h('div', this.$slots.default?.()) } }) }
})

import { useAuthStore } from '@/stores/auth'
import KontrolSesiOperatorView from './KontrolSesiOperatorView.vue'

const peserta = (id: number, nama: string, status = 'menunggu') => ({
  id,
  status,
  hadir: false,
  siswa: { id, nis: String(1000 + id), user: { id: 100 + id, name: nama }, kelas: { nama: '7A' } }
})

describe('Kontrol Sesi untuk guru pengawas', () => {
  beforeEach(() => {
    get.mockReset().mockImplementation((url: string) => {
      if (url === '/pengawasan/sesi') {
        return Promise.resolve({ data: { data: [{ id: 7, jadwal: 'UTS', mapel: 'IPA', ruang: 'Lab 1', tanggal: '2026-11-10', sesi_ke: 1 }] } })
      }
      if (url === '/sesi-ruang/7/peserta') {
        return Promise.resolve({
          data: {
            data: {
              ruang: { nama: 'Lab 1' },
              jadwal_ujian: { nama: 'UTS', mapel: { nama: 'IPA' } },
              sesi_ke: 1,
              token_ujian: { kode: 'ABC123' },
              peserta_ujian: [peserta(1, 'Ani'), peserta(2, 'Budi')]
            }
          }
        })
      }
      return Promise.reject(new Error(`URL tak terduga: ${url}`))
    })
    post.mockReset().mockResolvedValue({ data: { message: 'ok' } })
  })

  function pasang() {
    const pinia = createPinia()
    setActivePinia(pinia)
    useAuthStore().setUser({ id: 1, username: 'g', nama: 'Guru', role: 'guru' })
    return mount(KontrolSesiOperatorView, { global: { plugins: [pinia] } })
  }

  it('memakai daftar sesi yang diawasi, menyembunyikan aksi mulai/reset, dan menyimpan presensi', async () => {
    const wrapper = pasang()
    await flushPromises()

    expect(get).toHaveBeenCalledWith('/pengawasan/sesi')
    expect(get).not.toHaveBeenCalledWith('/jadwal')
    const teks = wrapper.text()
    expect(teks).toContain('Ani')
    expect(teks).toContain('Budi')
    expect(teks).toContain('Verifikasi kehadiran siswa di ruang yang Anda awasi.')
    expect(teks).not.toContain('Mulai Ujian Sesi Ini')
    expect(teks).not.toContain('Reset Semua Login')
    expect(wrapper.findAll('button').some((b) => b.text() === 'Mulai')).toBe(false)

    await wrapper.find('input[aria-label="Centang semua kehadiran"]').setValue(true)
    await wrapper.findAll('button').find((b) => b.text().includes('Simpan Status Presensi'))!.trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/sesi-ruang/7/kehadiran', {
      kehadiran: [
        { peserta_id: 1, hadir: true },
        { peserta_id: 2, hadir: true }
      ]
    })
    wrapper.unmount()
  })

  it('penyegaran data tidak menghapus centang hadir yang belum disimpan', async () => {
    const wrapper = pasang()
    await flushPromises()

    const kotak = () => wrapper.findAll('tbody input[type="checkbox"]')
    await kotak()[0].setValue(true)
    expect((kotak()[0].element as HTMLInputElement).checked).toBe(true)

    // Penyegaran (otomatis tiap 30 detik, di sini lewat tombol Segarkan): server masih menjawab hadir = false.
    await wrapper.find('button[aria-label="Segarkan"]').trigger('click')
    await flushPromises()
    expect((kotak()[0].element as HTMLInputElement).checked).toBe(true)
    expect((kotak()[1].element as HTMLInputElement).checked).toBe(false)
    wrapper.unmount()
  })
})
