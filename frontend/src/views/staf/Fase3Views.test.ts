import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

const { get, post, put, cetakLaporan } = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  put: vi.fn(),
  cetakLaporan: vi.fn()
}))

vi.mock('@/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/client')>()
  return { ...actual, apiClient: { get, post, put } }
})

vi.mock('@/lib/laporan', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/lib/laporan')>()
  return { ...actual, cetakLaporan }
})

vi.mock('@/components/StafLayout.vue', async () => {
  const { defineComponent, h } = await import('vue')
  return { default: defineComponent({ render() { return h('div', this.$slots.default?.()) } }) }
})

import { useAuthStore } from '@/stores/auth'
import AnalisisButirView from './AnalisisButirView.vue'
import AuditLogView from './AuditLogView.vue'
import BeritaAcaraView from './BeritaAcaraView.vue'
import CetakDokumenView from './CetakDokumenView.vue'

const sesi = [{ id: 7, jadwal: 'UTS', mapel: 'IPA', ruang: 'Lab 1', tanggal: '2026-11-10', sesi_ke: 1 }]
const jadwal = [{ id: 3, nama: 'UTS IPA', mapel: { nama: 'IPA' } }]

function pinia(role: 'guru' | 'operator' | 'superadmin') {
  const p = createPinia()
  setActivePinia(p)
  useAuthStore().$patch({ user: { id: 1, username: 'u', nama: 'U', role } })
  return p
}

beforeEach(() => {
  get.mockReset()
  post.mockReset()
  put.mockReset()
  cetakLaporan.mockReset().mockResolvedValue(undefined)
})

describe('BeritaAcaraView', () => {
  it('menyimpan baru dengan POST, lalu memperbarui dengan PUT, dan membuka tombol cetak', async () => {
    get.mockImplementation((url: string) => {
      if (url === '/pengawasan/sesi') return Promise.resolve({ data: { data: sesi } })
      if (url === '/sesi-ruang/7/berita-acara') return Promise.resolve({ data: { data: null } })
      return Promise.reject(new Error(url))
    })
    post.mockResolvedValue({ data: { data: { isi: 'Tertib', jumlah_hadir: 30, jumlah_absen: 2 } } })
    put.mockResolvedValue({ data: { data: { isi: 'Tertib sekali', jumlah_hadir: 30, jumlah_absen: 2 } } })

    const wrapper = mount(BeritaAcaraView, { global: { plugins: [pinia('guru')] } })
    await flushPromises()

    const tombol = (teks: string) => wrapper.findAll('button').find((b) => b.text().includes(teks))!
    expect(tombol('Simpan Berita Acara').attributes('disabled')).toBeDefined()
    expect(tombol('Cetak PDF').attributes('disabled')).toBeDefined()

    await wrapper.find('textarea').setValue('abc')
    expect(tombol('Simpan Berita Acara').attributes('disabled')).toBeDefined()
    await wrapper.find('textarea').setValue('Ujian tertib')
    await tombol('Simpan Berita Acara').trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/sesi-ruang/7/berita-acara', { isi: 'Ujian tertib' })
    expect(wrapper.text()).toContain('30')
    expect(wrapper.text()).toContain('dihitung otomatis oleh server')
    expect(tombol('Cetak PDF').attributes('disabled')).toBeUndefined()

    await wrapper.find('textarea').setValue('Ujian tertib sekali')
    await tombol('Perbarui Berita Acara').trigger('click')
    await flushPromises()
    expect(put).toHaveBeenCalledWith('/sesi-ruang/7/berita-acara', { isi: 'Ujian tertib sekali' })

    await tombol('Cetak PDF').trigger('click')
    await flushPromises()
    expect(cetakLaporan).toHaveBeenCalledWith('berita-acara', { sesi_ruang_id: 7 })
    wrapper.unmount()
  })

  it('memberi tahu pengawas yang belum punya sesi', async () => {
    get.mockResolvedValue({ data: { data: [] } })
    const wrapper = mount(BeritaAcaraView, { global: { plugins: [pinia('guru')] } })
    await flushPromises()
    expect(wrapper.text()).toContain('belum ditugaskan')
    wrapper.unmount()
  })
})

describe('CetakDokumenView', () => {
  beforeEach(() => {
    get.mockImplementation((url: string) => {
      if (url === '/jadwal') return Promise.resolve({ data: { data: jadwal } })
      if (url === '/pengawasan/sesi') return Promise.resolve({ data: { data: sesi } })
      if (url === '/kelas') return Promise.resolve({ data: { data: [{ id: 4, nama: 'X-1' }] } })
      return Promise.reject(new Error(url))
    })
  })

  it('guru tidak melihat kartu peserta dan mencetak daftar hadir per sesi', async () => {
    const wrapper = mount(CetakDokumenView, { global: { plugins: [pinia('guru')] } })
    await flushPromises()

    const opsiJenis = wrapper.findAll('select')[0].findAll('option').map((o) => o.text())
    expect(opsiJenis).not.toContain('Kartu Peserta Ujian')
    expect(opsiJenis).toContain('Daftar Hadir')

    await wrapper.findAll('button').find((b) => b.text().includes('Buat & Unduh PDF'))!.trigger('click')
    await flushPromises()
    expect(cetakLaporan).toHaveBeenCalledWith(
      'daftar-hadir',
      { jadwal_id: 3, sesi_ruang_id: 7, kelas_id: undefined },
      expect.any(Object)
    )
    expect(wrapper.text()).toContain('berhasil dibuat')
    wrapper.unmount()
  })

  it('operator mencetak kartu peserta per jadwal dengan filter kelas', async () => {
    const wrapper = mount(CetakDokumenView, { global: { plugins: [pinia('operator')] } })
    await flushPromises()
    await wrapper.findAll('select')[0].setValue('kartu-peserta')
    await flushPromises()
    await wrapper.findAll('select')[2].setValue('4')
    await wrapper.findAll('button').find((b) => b.text().includes('Buat & Unduh PDF'))!.trigger('click')
    await flushPromises()

    expect(cetakLaporan.mock.calls[0][0]).toBe('kartu-peserta')
    expect(cetakLaporan.mock.calls[0][1]).toMatchObject({ jadwal_id: 3, kelas_id: 4 })
    wrapper.unmount()
  })

  it('menampilkan pesan error bila pembuatan PDF gagal', async () => {
    cetakLaporan.mockRejectedValue(new Error('Pembuatan PDF gagal.'))
    const wrapper = mount(CetakDokumenView, { global: { plugins: [pinia('operator')] } })
    await flushPromises()
    await wrapper.findAll('button').find((b) => b.text().includes('Buat & Unduh PDF'))!.trigger('click')
    await flushPromises()
    expect(wrapper.find('[role="alert"]').text()).toContain('Pembuatan PDF gagal')
    wrapper.unmount()
  })
})

describe('AnalisisButirView', () => {
  it('menampilkan kategori kesukaran dan daya pembeda per butir', async () => {
    get.mockImplementation((url: string) => {
      if (url === '/jadwal') return Promise.resolve({ data: { data: jadwal } })
      if (url === '/kelas') return Promise.resolve({ data: { data: [] } })
      if (url === '/analisis-butir/3') {
        return Promise.resolve({
          data: {
            data: {
              jumlah_peserta_final: 10,
              metode: { indeks_kesukaran: 'm1', daya_pembeda: 'm2' },
              butir: [
                { nomor: 1, soal_id: 1, tipe: 'pg', skor_maksimal: '2.00', jumlah_peserta_final: 10, indeks_kesukaran: 0.9, daya_pembeda: 0.45 },
                { nomor: 2, soal_id: 2, tipe: 'essay', skor_maksimal: '5.00', jumlah_peserta_final: 10, indeks_kesukaran: 0.2, daya_pembeda: -0.1 }
              ]
            }
          }
        })
      }
      return Promise.reject(new Error(url))
    })
    const wrapper = mount(AnalisisButirView, { global: { plugins: [pinia('guru')] } })
    await flushPromises()

    const baris = wrapper.findAll('tbody tr')
    expect(baris[0].text()).toContain('Mudah')
    expect(baris[0].text()).toContain('Sangat baik')
    expect(baris[1].text()).toContain('Sulit')
    expect(baris[1].text()).toContain('Buruk (negatif)')
    wrapper.unmount()
  })

  it('memberi catatan saat peserta final kurang dari 3', async () => {
    get.mockImplementation((url: string) => {
      if (url === '/jadwal') return Promise.resolve({ data: { data: jadwal } })
      if (url === '/kelas') return Promise.resolve({ data: { data: [] } })
      return Promise.resolve({
        data: { data: { jumlah_peserta_final: 2, metode: { indeks_kesukaran: 'a', daya_pembeda: 'b' }, butir: [] } }
      })
    })
    const wrapper = mount(AnalisisButirView, { global: { plugins: [pinia('guru')] } })
    await flushPromises()
    expect(wrapper.text()).toContain('minimal 3 peserta final')
    wrapper.unmount()
  })
})

describe('AuditLogView', () => {
  const log = (id: number, aksi: string) => ({
    id,
    aksi,
    target_type: 'peserta_ujian',
    target_id: id,
    data: { pemicu: 'blur' },
    created_at: '2026-11-10T08:00:00Z',
    aktor: { name: 'Pengawas Satu', username: 'pengawas1', role: 'guru' }
  })

  it('menampilkan catatan dan menerapkan filter aksi serta pencarian aktor', async () => {
    get.mockResolvedValue({ data: { data: [log(1, 'ujian.lock')], last_page: 3, total: 60 } })
    const wrapper = mount(AuditLogView, { global: { plugins: [pinia('superadmin')] } })
    await flushPromises()

    expect(wrapper.text()).toContain('Pengawas Satu')
    expect(wrapper.text()).toContain('ujian.lock')
    expect(wrapper.text()).toContain('60 catatan')
    expect(get).toHaveBeenLastCalledWith('/log-aktivitas', { params: { page: 1, per_page: 25 } })

    await wrapper.find('select').setValue('ujian.unlock')
    await flushPromises()
    expect(get).toHaveBeenLastCalledWith('/log-aktivitas', { params: { page: 1, per_page: 25, aksi: 'ujian.unlock' } })

    await wrapper.findAll('button').find((b) => b.text() === 'Berikutnya')!.trigger('click')
    await flushPromises()
    expect(get).toHaveBeenLastCalledWith('/log-aktivitas', { params: { page: 2, per_page: 25, aksi: 'ujian.unlock' } })
    wrapper.unmount()
  })

  it('menjadikan tanggal akhir inklusif sampai akhir hari', async () => {
    get.mockResolvedValue({ data: { data: [], last_page: 1, total: 0 } })
    const wrapper = mount(AuditLogView, { global: { plugins: [pinia('superadmin')] } })
    await flushPromises()
    await wrapper.findAll('input[type="date"]')[1].setValue('2026-11-10')
    await flushPromises()
    expect(get).toHaveBeenLastCalledWith('/log-aktivitas', { params: { page: 1, per_page: 25, sampai: '2026-11-10 23:59:59' } })
    expect(wrapper.text()).toContain('Tidak ada catatan yang cocok')
    wrapper.unmount()
  })
})
