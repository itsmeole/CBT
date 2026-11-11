import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const { get, put } = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn() }))

vi.mock('@/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/client')>()
  return { ...actual, apiClient: { get, put } }
})

vi.mock('@/components/StafLayout.vue', async () => {
  const { defineComponent, h } = await import('vue')
  return { default: defineComponent({ render() { return h('div', this.$slots.default?.()) } }) }
})

import { useAuthStore } from '@/stores/auth'
import KoreksiEssayView from './KoreksiEssayView.vue'

const antrean = [
  {
    jawaban_id: 50, peserta_id: 1, soal_id: 9, nama_siswa: 'Budi', nis: '001', kelas: 'X-1',
    konten_soal: '<p>Jelaskan fotosintesis</p>', kunci: 'Tumbuhan membuat makanan', rubrik: 'Skor penuh jika lengkap',
    jawaban: 'Proses membuat makanan', skor: '0.00', catatan: null, skor_maksimal: '10.00'
  },
  {
    jawaban_id: null, peserta_id: 2, soal_id: 9, nama_siswa: 'Kosong', nis: '002', kelas: 'X-1',
    konten_soal: '<p>Jelaskan fotosintesis</p>', kunci: 'k', rubrik: 'r', jawaban: null, skor: '0.00', catatan: null, skor_maksimal: '10.00'
  }
]

function pasang() {
  return mount(KoreksiEssayView, {
    global: { plugins: [createPinia()] }
  })
}

describe('KoreksiEssayView', () => {
  beforeEach(() => {
    get.mockReset().mockImplementation((url: string) => {
      if (url === '/mapel') return Promise.resolve({ data: { data: [{ id: 1, nama: 'IPA' }, { id: 2, nama: 'Matematika' }] } })
      if (url === '/kelas') return Promise.resolve({ data: { data: [{ id: 4, nama: 'X-1' }] } })
      if (url === '/jadwal') {
        return Promise.resolve({
          data: { data: [{ id: 3, nama: 'UTS IPA', mapel_id: 1, mapel: { nama: 'IPA' } }, { id: 5, nama: 'UTS MTK', mapel_id: 2, mapel: { nama: 'Matematika' } }] }
        })
      }
      if (url === '/koreksi/essay') return Promise.resolve({ data: { data: antrean } })
      return Promise.reject(new Error(`URL tak terduga: ${url}`))
    })
    put.mockReset().mockResolvedValue({ data: {} })
  })

  it('menampilkan soal, kunci, rubrik, jawaban, dan melaporkan jawaban kosong terpisah', async () => {
    const wrapper = pasang()
    await flushPromises()

    expect(get).toHaveBeenCalledWith('/koreksi/essay', { params: { jadwal_id: 3 } })
    const teks = wrapper.text()
    expect(teks).toContain('Jelaskan fotosintesis')
    expect(teks).toContain('Tumbuhan membuat makanan')
    expect(teks).toContain('Skor penuh jika lengkap')
    expect(teks).toContain('Proses membuat makanan')
    expect(teks).toContain('1 jawaban kosong dinilai 0 otomatis')
    wrapper.unmount()
  })

  it('menolak skor di luar batas bobot dan hanya mengirim skor valid', async () => {
    const wrapper = pasang()
    await flushPromises()
    const simpan = () => wrapper.findAll('button').find((b) => b.text().includes('Simpan Skor'))!

    await wrapper.find('input[inputmode="decimal"]').setValue('11')
    expect(simpan().attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('Skor harus angka 0 sampai 10.00')

    await wrapper.find('input[inputmode="decimal"]').setValue('7,5')
    await wrapper.find('textarea').setValue('Bagus')
    expect(simpan().attributes('disabled')).toBeUndefined()
    await simpan().trigger('click')
    await flushPromises()

    expect(put).toHaveBeenCalledWith('/koreksi/essay/50', { skor: '7.5', catatan: 'Bagus' })
    expect(wrapper.text()).toContain('Tidak ada jawaban essay yang perlu dikoreksi')
    wrapper.unmount()
  })
})

describe('KoreksiEssayView filter', () => {
  beforeEach(() => {
    get.mockReset().mockImplementation((url: string) => {
      if (url === '/mapel') return Promise.resolve({ data: { data: [{ id: 1, nama: 'IPA' }, { id: 2, nama: 'Matematika' }] } })
      if (url === '/kelas') return Promise.resolve({ data: { data: [{ id: 4, nama: 'X-1' }] } })
      if (url === '/jadwal') {
        return Promise.resolve({
          data: { data: [{ id: 3, nama: 'UTS IPA', mapel_id: 1, mapel: { nama: 'IPA' } }, { id: 5, nama: 'UTS MTK', mapel_id: 2, mapel: { nama: 'Matematika' } }] }
        })
      }
      if (url === '/koreksi/essay') return Promise.resolve({ data: { data: [] } })
      return Promise.reject(new Error(`URL tak terduga: ${url}`))
    })
  })

  it('memilih mapel menyaring pilihan jadwal dan memuat ulang antrean', async () => {
    const wrapper = pasang()
    await flushPromises()
    const [mapel, jadwal] = wrapper.findAll('select')
    expect(jadwal.findAll('option').map((o) => o.text())).toEqual(['UTS IPA - IPA', 'UTS MTK - Matematika'])

    await mapel.setValue('2')
    await flushPromises()
    expect(jadwal.findAll('option').map((o) => o.text())).toEqual(['UTS MTK - Matematika'])
    expect(get).toHaveBeenLastCalledWith('/koreksi/essay', { params: { jadwal_id: 5 } })
    wrapper.unmount()
  })

  it('memilih kelas mengirim kelas_id ke server', async () => {
    const wrapper = pasang()
    await flushPromises()
    await wrapper.findAll('select')[2].setValue('4')
    await flushPromises()
    expect(get).toHaveBeenLastCalledWith('/koreksi/essay', { params: { jadwal_id: 3, kelas_id: 4 } })
    wrapper.unmount()
  })

  it('operator hanya melihat jawaban (termasuk yang sudah dikoreksi), tanpa formulir skor', async () => {
    get.mockImplementation((url: string) => {
      if (url === '/mapel') return Promise.resolve({ data: { data: [{ id: 1, nama: 'IPA' }] } })
      if (url === '/kelas') return Promise.resolve({ data: { data: [{ id: 4, nama: 'X-1' }] } })
      if (url === '/jadwal') return Promise.resolve({ data: { data: [{ id: 3, nama: 'UTS IPA', mapel_id: 1, mapel: { nama: 'IPA' } }] } })
      if (url === '/koreksi/essay') {
        return Promise.resolve({ data: { data: [{ ...antrean[0], sudah_dikoreksi: true, skor: '8.50', catatan: 'Bagus' }] } })
      }
      return Promise.reject(new Error(`URL tak terduga: ${url}`))
    })
    const pinia = createPinia()
    setActivePinia(pinia)
    useAuthStore().setUser({ id: 1, username: 'op', nama: 'Operator', role: 'operator' })
    const wrapper = mount(KoreksiEssayView, { global: { plugins: [pinia] } })
    await flushPromises()

    expect(get).toHaveBeenCalledWith('/koreksi/essay', { params: { jadwal_id: 3, termasuk_dikoreksi: 1 } })
    const teks = wrapper.text()
    expect(teks).toContain('Proses membuat makanan')
    expect(teks).toContain('Sudah dikoreksi: skor 8.50 dari 10.00')
    expect(teks).toContain('Catatan: Bagus')
    expect(wrapper.find('input[inputmode="decimal"]').exists()).toBe(false)
    expect(wrapper.findAll('button').some((b) => b.text().includes('Simpan Skor'))).toBe(false)
    wrapper.unmount()
  })
})
