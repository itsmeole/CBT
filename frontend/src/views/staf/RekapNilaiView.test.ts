import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const { get } = vi.hoisted(() => ({ get: vi.fn() }))

vi.mock('@/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/client')>()
  return { ...actual, apiClient: { get } }
})

vi.mock('@/components/StafLayout.vue', async () => {
  const { defineComponent, h } = await import('vue')
  return { default: defineComponent({ render() { return h('div', this.$slots.default?.()) } }) }
})

import RekapNilaiView from './RekapNilaiView.vue'

const baris = [
  { peserta_id: 1, nama_siswa: 'Budi', nis: '001', kelas: 'X-1', status_peserta: 'selesai', nilai_status: 'final', total: '8.00', maksimal: '10.00', nilai_akhir: '80.00' },
  { peserta_id: 2, nama_siswa: 'Citra', nis: '002', kelas: 'X-1', status_peserta: 'selesai', nilai_status: 'menunggu_koreksi', total: '5.00', maksimal: '10.00', nilai_akhir: '50.00' },
  { peserta_id: 3, nama_siswa: 'Dewi', nis: '003', kelas: 'X-2', status_peserta: 'mengerjakan', nilai_status: null, total: null, maksimal: null, nilai_akhir: null }
]

function pasang() {
  return mount(RekapNilaiView, {  })
}

describe('RekapNilaiView', () => {
  beforeEach(() => {
    get.mockReset().mockImplementation((url: string) => {
      if (url === '/jadwal') return Promise.resolve({ data: { data: [{ id: 3, nama: 'UTS IPA', mapel: { nama: 'IPA' } }] } })
      if (url === '/nilai') return Promise.resolve({ data: { data: baris } })
      return Promise.reject(new Error(`URL tak terduga: ${url}`))
    })
  })

  it('membedakan status Final, Menunggu Koreksi Essay, dan belum selesai', async () => {
    const wrapper = pasang()
    await flushPromises()

    const baris = wrapper.findAll('tbody tr')
    expect(baris[0].text()).toContain('Final')
    expect(baris[0].text()).toContain('80.00')
    expect(baris[1].text()).toContain('Menunggu Koreksi Essay')
    expect(baris[2].text()).toContain('Belum selesai')
    expect(wrapper.text()).toContain('Final: 1')
    expect(wrapper.text()).toContain('Menunggu koreksi essay: 1')
    wrapper.unmount()
  })

  it('mencari berdasarkan nama atau NIS', async () => {
    const wrapper = pasang()
    await flushPromises()
    await wrapper.find('input[placeholder^="Ketik"]').setValue('citra')
    expect(wrapper.findAll('tbody tr')).toHaveLength(1)
    expect(wrapper.text()).toContain('Citra')
    wrapper.unmount()
  })
})
