import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'

const { get } = vi.hoisted(() => ({ get: vi.fn() }))

vi.mock('@/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/client')>()
  return { ...actual, apiClient: { get } }
})

vi.mock('@/components/StafLayout.vue', async () => {
  const { defineComponent, h } = await import('vue')
  return { default: defineComponent({ render() { return h('div', this.$slots.default?.()) } }) }
})

import { useAuthStore } from '@/stores/auth'
import type { DataDashboard, PanelGuru, PanelOperator, PanelSistem } from '@/lib/dashboard'
import BerandaStafView from './BerandaStafView.vue'

const TANGGAL = '2026-11-10'

const peserta = (o: Partial<PanelOperator['hari_ini'][0]['peserta']> = {}) => ({ total: 10, hadir: 9, menunggu: 0, mengerjakan: 5, terkunci: 2, selesai: 2, ...o })

function operator(ubah: Partial<PanelOperator> = {}): PanelOperator {
  return {
    hari_ini: [
      { id: 1, jadwal: 'UTS Semester', mapel: 'IPA', ruang: 'Lab 1', sesi_ke: 1, jam_mulai: '08:00', jam_akhir: '09:30', token: 'K7M2PQ', pengawas: ['Bu Ani'], peserta: peserta(), status: 'berjalan' },
      { id: 2, jadwal: 'UTS Semester', mapel: 'Matematika', ruang: 'Lab 2', sesi_ke: 2, jam_mulai: '10:00', jam_akhir: '11:30', token: null, pengawas: [], peserta: peserta({ total: 4, hadir: 0, mengerjakan: 0, terkunci: 0, selesai: 0 }), status: 'belum_mulai' }
    ],
    langsung: { sesi_hari_ini: 2, sesi_berjalan: 1, peserta: 14, menunggu: 0, mengerjakan: 5, terkunci: 2, selesai: 2 },
    kesiapan: [
      { jadwal_id: 7, nama: 'UTS IPA Besok', mapel: 'IPA', tanggal: '2026-11-11', jumlah_sesi: 2, siap: false, masalah: [{ kode: 'tanpa_pengawas', pesan: '1 sesi belum punya pengawas.', jumlah: 1 }] },
      { jadwal_id: 8, nama: 'UAS Seni', mapel: 'Seni', tanggal: '2026-11-15', jumlah_sesi: 1, siap: true, masalah: [] }
    ],
    perlu_tindakan: [{ kode: 'terkunci', pesan: '2 siswa sedang terkunci dan menunggu dibuka pengawas.', jumlah: 2, tautan: '/staf/monitoring' }],
    ...ubah
  }
}

function guru(ubah: Partial<PanelGuru> = {}): PanelGuru {
  return {
    koreksi: { total: 5, jumlah_jadwal: 1, per_jadwal: [{ jadwal_id: 3, nama: 'UTS IPA', mapel: 'IPA', tanggal: '2026-11-09', menunggu: 5 }] },
    pengawasan: {
      sesi: [
        { id: 1, jadwal: 'UTS IPA', mapel: 'IPA', ruang: 'Lab 1', tanggal: TANGGAL, sesi_ke: 1, jam_mulai: '08:00', jam_akhir: '09:30', hari_ini: true },
        { id: 2, jadwal: 'UAS IPA', mapel: 'IPA', ruang: 'Lab 2', tanggal: '2026-11-12', sesi_ke: 1, jam_mulai: '08:00', jam_akhir: '09:30', hari_ini: false }
      ],
    },
    jadwal_saya: [
      { jadwal_id: 3, nama: 'UTS IPA', mapel: 'IPA', tanggal: TANGGAL, paket: 'Paket UTS', jumlah_butir: 40 },
      { jadwal_id: 4, nama: 'UAS IPA', mapel: 'IPA', tanggal: '2026-11-12', paket: null, jumlah_butir: 0 }
    ],
    hasil_terbaru: [{ jadwal_id: 2, nama: 'Ulangan Harian', mapel: 'IPA', tanggal: '2026-11-08', final: 28, menunggu: 4, rata_rata: 76.5 }],
    ...ubah
  }
}

function sistem(ubah: Partial<PanelSistem> = {}): PanelSistem {
  return {
    kesehatan: { antrean_menunggu: 3, job_gagal: 2, pdf_gagal_7_hari: 1, backup_terakhir: new Date(Date.now() - 3_600_000).toISOString() },
    aktivitas: [
      { id: 2, aksi: 'ujian.unlock', aktor: 'Pak Budi', peran: 'guru', waktu: new Date(Date.now() - 120_000).toISOString() },
      { id: 1, aksi: 'auth.login', aktor: 'Operator Satu', peran: 'operator', waktu: new Date(Date.now() - 7_200_000).toISOString() }
    ],
    kualitas_data: { tahun_ajaran_aktif: { nama: '2026/2027', semester: 1 }, guru_tanpa_pengampu: 2, mapel_tanpa_pengampu: 0, kelas_tanpa_siswa: 1, akun_nonaktif: 0 },
    pengguna: { superadmin: 1, operator: 2, guru: 30, siswa: 700 },
    ...ubah
  }
}

function respons(peran: DataDashboard['peran'], bagian: Partial<DataDashboard>): { data: { data: DataDashboard } } {
  return { data: { data: { peran, tanggal: TANGGAL, zona_waktu: 'Asia/Jakarta', ...bagian } } }
}

async function pasang(peran: DataDashboard['peran']) {
  const pinia = createPinia()
  setActivePinia(pinia)
  useAuthStore().$patch({ user: { id: 1, username: 'u', nama: 'Budi Santoso', role: peran } })
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:pathMatch(.*)*', component: { render: () => null } }] })
  await router.push('/staf')
  await router.isReady()
  const wrapper = mount(BerandaStafView, { global: { plugins: [pinia, router] } })
  await flushPromises()
  return wrapper
}

beforeEach(() => get.mockReset())
afterEach(() => vi.useRealTimers())

describe('Dashboard operator', () => {
  it('menampilkan angka langsung, jadwal hari ini lengkap dengan token dan status, serta tindakan', async () => {
    get.mockResolvedValue(respons('operator', { operator: operator() }))
    const wrapper = await pasang('operator')
    const teks = wrapper.text()

    expect(teks).toContain('Panel Operator')
    expect(teks).toContain('Budi Santoso')
    expect(teks).toContain('Selasa, 10 November 2026')
    expect(teks).toContain('Sesi hari ini')
    expect(teks).toContain('1 sedang berjalan')
    expect(teks).toContain('08:00-09:30')
    expect(teks).toContain('K7M2PQ')
    expect(teks).toContain('Berjalan')
    expect(teks).toContain('Belum mulai')
    expect(teks).toContain('belum ditetapkan')
    expect(teks).toContain('2 siswa sedang terkunci dan menunggu dibuka pengawas.')
    expect(wrapper.find('a[href="/staf/monitoring"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('menampilkan kesiapan ujian dengan masalah konkret, "Besok", dan tanda Siap', async () => {
    get.mockResolvedValue(respons('operator', { operator: operator() }))
    const wrapper = await pasang('operator')
    const teks = wrapper.text()

    expect(teks).toContain('1 siap, 1 perlu perhatian')
    expect(teks).toContain('UTS IPA Besok')
    expect(teks).toContain('Besok')
    expect(teks).toContain('1 masalah')
    expect(teks).toContain('1 sesi belum punya pengawas.')
    expect(teks).toContain('Siap')
    wrapper.unmount()
  })

  it('menampilkan keadaan kosong yang ramah bila tidak ada ujian dan tidak ada tindakan', async () => {
    get.mockResolvedValue(
      respons('operator', {
        operator: operator({
          hari_ini: [],
          kesiapan: [],
          perlu_tindakan: [],
          langsung: { sesi_hari_ini: 0, sesi_berjalan: 0, peserta: 0, menunggu: 0, mengerjakan: 0, terkunci: 0, selesai: 0 }
        })
      })
    )
    const wrapper = await pasang('operator')
    const teks = wrapper.text()

    expect(teks).toContain('Tidak ada ujian terjadwal hari ini.')
    expect(teks).toContain('Semua beres. Tidak ada tindakan yang menunggu.')
    expect(teks).toContain('Belum ada ujian terjadwal dalam 14 hari ke depan.')
    wrapper.unmount()
  })
})

describe('Dashboard guru', () => {
  it('menampilkan antrean koreksi, dan tugas pengawasan', async () => {
    get.mockResolvedValue(respons('guru', { guru: guru() }))
    const wrapper = await pasang('guru')
    const teks = wrapper.text()

    expect(teks).toContain('Panel Guru')
    expect(teks).toContain('Essay menunggu koreksi')
    expect(teks).toContain('5 jawaban')
    expect(wrapper.find('a[href="/staf/koreksi-essay"]').exists()).toBe(true)
    expect(teks).toContain('Tugas pengawasan saya')
    expect(teks).toContain('Hari ini')
    expect(teks).not.toContain('berita acara')
    wrapper.unmount()
  })

  it('menampilkan hasil terbaru dan menandai paket kosong pada ujian mendatang', async () => {
    get.mockResolvedValue(respons('guru', { guru: guru() }))
    const wrapper = await pasang('guru')
    const teks = wrapper.text()

    expect(teks).toContain('76.5')
    expect(teks).toContain('Final: 28')
    expect(teks).toContain('Menunggu koreksi: 4')
    expect(teks).toContain('40 butir')
    expect(teks).toContain('0 butir')
    wrapper.unmount()
  })

  it('keadaan kosong guru', async () => {
    get.mockResolvedValue(
      respons('guru', {
        guru: guru({
          koreksi: { total: 0, jumlah_jadwal: 0, per_jadwal: [] },
          pengawasan: { sesi: [] },
          jadwal_saya: [],
          hasil_terbaru: []
        })
      })
    )
    const wrapper = await pasang('guru')
    const teks = wrapper.text()

    expect(teks).toContain('Tidak ada jawaban essay yang perlu dikoreksi.')
    expect(teks).toContain('Anda belum ditugaskan mengawasi sesi apa pun.')
    expect(teks).toContain('Belum ada hasil ujian.')
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    wrapper.unmount()
  })
})

describe('Dashboard superadmin', () => {
  it('menampilkan kesehatan sistem, aktivitas berlabel, kualitas data, dan panel operasional', async () => {
    get.mockResolvedValue(respons('superadmin', { sistem: sistem(), operator: operator() }))
    const wrapper = await pasang('superadmin')
    const teks = wrapper.text()

    expect(teks).toContain('Panel Superadmin')
    expect(teks).toContain('Tugas gagal')
    expect(teks).toContain('Membuka kunci siswa')
    expect(teks).toContain('Pak Budi')
    expect(teks).toContain('2 menit lalu')
    expect(teks).toContain('Guru tanpa pengampu mapel')
    expect(teks).toContain('2027')
    expect(teks).toContain('700')
    expect(teks).toContain('Operasional ujian')
    expect(teks).toContain('Jadwal hari ini')
    expect(wrapper.find('a[href="/staf/master?tab=guru_mapel"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('memperingatkan bila tidak ada tahun ajaran aktif dan backup belum pernah dibuat', async () => {
    get.mockResolvedValue(
      respons('superadmin', {
        operator: operator(),
        sistem: sistem({
          kesehatan: { antrean_menunggu: null, job_gagal: 0, pdf_gagal_7_hari: 0, backup_terakhir: null },
          kualitas_data: { tahun_ajaran_aktif: null, guru_tanpa_pengampu: 0, mapel_tanpa_pengampu: 0, kelas_tanpa_siswa: 0, akun_nonaktif: 0 }
        })
      })
    )
    const wrapper = await pasang('superadmin')
    const teks = wrapper.text()

    expect(teks).toContain('Belum ada tahun ajaran aktif.')
    expect(teks).toContain('Belum ada backup')
    expect(teks).toContain('tidak dapat dibaca')
    wrapper.unmount()
  })
})

describe('Pemuatan dan pembaruan', () => {
  it('menampilkan galat dengan tombol coba lagi, lalu memuat saat dicoba ulang', async () => {
    get.mockRejectedValueOnce(new Error('Server tidak merespons.')).mockResolvedValue(respons('operator', { operator: operator() }))
    const wrapper = await pasang('operator')

    expect(wrapper.find('[role="alert"]').text()).toContain('Server tidak merespons.')
    await wrapper.findAll('button').find((b) => b.text() === 'Coba lagi')!.trigger('click')
    await flushPromises()

    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Jadwal hari ini')
    wrapper.unmount()
  })

  it('memperbarui otomatis tiap 30 detik, tetap menampilkan data lama bila pembaruan gagal, dan berhenti saat tab tersembunyi', async () => {
    vi.useFakeTimers()
    get.mockResolvedValue(respons('operator', { operator: operator() }))
    const wrapper = await pasang('operator')
    expect(get).toHaveBeenCalledTimes(1)

    await vi.advanceTimersByTimeAsync(30_000)
    expect(get).toHaveBeenCalledTimes(2)

    get.mockRejectedValueOnce(new Error('Jaringan putus.'))
    await vi.advanceTimersByTimeAsync(30_000)
    expect(get).toHaveBeenCalledTimes(3)
    expect(wrapper.find('[role="alert"]').text()).toContain('Menampilkan data terakhir yang berhasil dimuat.')
    expect(wrapper.text()).toContain('K7M2PQ')

    Object.defineProperty(document, 'visibilityState', { value: 'hidden', configurable: true })
    await vi.advanceTimersByTimeAsync(60_000)
    expect(get).toHaveBeenCalledTimes(3)
    Object.defineProperty(document, 'visibilityState', { value: 'visible', configurable: true })
    wrapper.unmount()
  })

  it('menghentikan pembaruan otomatis setelah halaman ditutup', async () => {
    vi.useFakeTimers()
    get.mockResolvedValue(respons('operator', { operator: operator() }))
    const wrapper = await pasang('operator')
    wrapper.unmount()

    await vi.advanceTimersByTimeAsync(120_000)
    expect(get).toHaveBeenCalledTimes(1)
  })
})

describe('Urutan dan pembatasan tampilan', () => {
  it('superadmin: operasional ujian tampil di atas, sistem dan data di bawahnya', async () => {
    get.mockResolvedValue(respons('superadmin', { sistem: sistem(), operator: operator() }))
    const wrapper = await pasang('superadmin')
    const teks = wrapper.text()

    expect(teks.indexOf('Operasional ujian')).toBeGreaterThan(-1)
    expect(teks.indexOf('Operasional ujian')).toBeLessThan(teks.indexOf('Sistem dan data'))
    expect(teks.indexOf('Jadwal hari ini')).toBeLessThan(teks.indexOf('Aktivitas terbaru'))
    expect(teks.indexOf('Sedang mengerjakan')).toBeLessThan(teks.indexOf('Tugas gagal'))
    wrapper.unmount()
  })

  it('guru: Ujian mapel saya dan Antrean koreksi essay bertukar posisi', async () => {
    get.mockResolvedValue(respons('guru', { guru: guru() }))
    const wrapper = await pasang('guru')

    const judul = wrapper.findAll('h2').map((h) => h.text())
    expect(judul).toEqual(['Ujian mapel saya', 'Hasil ujian terbaru', 'Tugas pengawasan saya', 'Antrean koreksi essay'])
    wrapper.unmount()
  })

  it('guru: antrean koreksi menyebut pembatasan 5 terbaru bila ada jadwal lain yang tidak ditampilkan', async () => {
    const lima = Array.from({ length: 5 }, (_, i) => ({ jadwal_id: i + 1, nama: `Ujian ${i + 1}`, mapel: 'IPA', tanggal: '2026-11-09', menunggu: 2 }))
    get.mockResolvedValue(respons('guru', { guru: guru({ koreksi: { total: 14, jumlah_jadwal: 7, per_jadwal: lima } }) }))
    const wrapper = await pasang('guru')
    const teks = wrapper.text()

    expect(teks).toContain('Menampilkan 5 jadwal terbaru dari 7 jadwal yang punya antrean.')
    expect(teks).toContain('14')
    expect(teks).toContain('7 jadwal')
    expect(teks.match(/2 jawaban/g)).toHaveLength(5)
    wrapper.unmount()
  })

  it('guru: tanpa pembatasan, keterangan antrean tetap standar', async () => {
    get.mockResolvedValue(respons('guru', { guru: guru() }))
    const wrapper = await pasang('guru')

    expect(wrapper.text()).toContain('Jawaban yang sudah dikumpulkan dan belum diberi skor.')
    expect(wrapper.text()).not.toContain('Menampilkan')
    wrapper.unmount()
  })
})
