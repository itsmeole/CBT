import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia } from 'pinia'

const { get, put } = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn() }))

vi.mock('@/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/client')>()
  return { ...actual, apiClient: { get, put } }
})

vi.mock('@/components/StafLayout.vue', async () => {
  const { defineComponent, h } = await import('vue')
  return { default: defineComponent({ render() { return h('div', this.$slots.default?.()) } }) }
})

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
      if (url === '/jadwal') return Promise.resolve({ data: { data: [{ id: 3, nama: 'UTS IPA', mapel: { nama: 'IPA' } }] } })
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
