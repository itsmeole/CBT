import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const { get, post, del } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), del: vi.fn() }))

vi.mock('@/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/client')>()
  return { ...actual, apiClient: { get, post, delete: del } }
})

import PenempatanRuangModal from './PenempatanRuangModal.vue'
import PengawasSesiModal from './PengawasSesiModal.vue'

const jadwal = { id: 9, nama: 'UTS IPA', tanggal: '2026-11-10', tahun_ajaran_id: 1 }

const siswa = (id: number, kelasId: number, nama: string) => ({
  id,
  nis: String(1000 + id),
  kelas_id: kelasId,
  kelas: { nama: kelasId === 1 ? '7A' : '7B' },
  user: { name: nama }
})

const jadwalLain = {
  id: 5,
  nama: 'UTS Matematika',
  tanggal: '2026-11-09',
  tahun_ajaran_id: 1,
  mapel: { nama: 'Matematika' },
  sesi_ruang: [{ id: 1 }, { id: 2 }]
}

function mockData(sesiAda: unknown[] = [], daftarJadwal: unknown[] = []) {
  get.mockImplementation((url: string) => {
    if (url === '/jadwal') return Promise.resolve({ data: { data: daftarJadwal } })
    if (url === '/jadwal/5/penempatan') {
      return Promise.resolve({
        data: {
          data: {
            sesi: [
              { ruang_id: 10, peserta: [{ siswa_id: 1 }, { siswa_id: 2 }] },
              { ruang_id: 11, peserta: [{ siswa_id: 3 }, { siswa_id: 4 }, { siswa_id: 5 }] }
            ]
          }
        }
      })
    }
    if (url === '/kelas') {
      return Promise.resolve({ data: { data: [{ id: 1, nama: '7A', tahun_ajaran_id: 1 }, { id: 2, nama: '7B', tahun_ajaran_id: 1 }, { id: 3, nama: '9Z', tahun_ajaran_id: 2 }] } })
    }
    if (url === '/siswa') {
      return Promise.resolve({
        data: { data: [siswa(1, 1, 'Ani'), siswa(2, 1, 'Budi'), siswa(3, 1, 'Citra'), siswa(4, 1, 'Dedi'), siswa(5, 2, 'Eka'), siswa(6, 2, 'Fani')] }
      })
    }
    if (url === '/ruang') {
      return Promise.resolve({ data: { data: [{ id: 10, nama: 'R1', kapasitas: 4 }, { id: 11, nama: 'R2', kapasitas: 4 }] } })
    }
    if (url === '/jadwal/9/penempatan') return Promise.resolve({ data: { data: { sesi: sesiAda } } })
    return Promise.reject(new Error(`URL tak terduga: ${url}`))
  })
}

const centang = async (wrapper: ReturnType<typeof mount>, label: string) => {
  const kotak = wrapper.findAll('label').find((l) => l.text().startsWith(label))!
  await kotak.find('input[type="checkbox"]').setValue(true)
}
const tombol = (wrapper: ReturnType<typeof mount>, teks: string) => wrapper.findAll('button').find((b) => b.text().includes(teks))!

describe('PenempatanRuangModal', () => {
  beforeEach(() => {
    get.mockReset()
    post.mockReset().mockResolvedValue({ data: {} })
    del.mockReset().mockResolvedValue({ data: {} })
  })

  it('hanya menawarkan kelas pada tahun ajaran jadwal', async () => {
    mockData()
    const wrapper = mount(PenempatanRuangModal, { props: { jadwal } })
    await flushPromises()
    const teks = wrapper.text()
    expect(teks).toContain('7A')
    expect(teks).toContain('7B')
    expect(teks).not.toContain('9Z')
    wrapper.unmount()
  })

  it('acak membagi semua siswa ke ruang terpilih dan menyimpan payload yang valid', async () => {
    mockData()
    const wrapper = mount(PenempatanRuangModal, { props: { jadwal } })
    await flushPromises()
    expect(tombol(wrapper, 'Simpan Penempatan').attributes('disabled')).toBeDefined()

    await centang(wrapper, '7A')
    await centang(wrapper, '7B')
    await centang(wrapper, 'R1')
    await centang(wrapper, 'R2')
    await tombol(wrapper, 'Acak Penempatan').trigger('click')

    expect(tombol(wrapper, 'Simpan Penempatan').attributes('disabled')).toBeUndefined()
    expect(wrapper.text()).toContain('R1: 3/4')
    expect(wrapper.text()).toContain('R2: 3/4')

    await tombol(wrapper, 'Simpan Penempatan').trigger('click')
    await flushPromises()

    const [url, body] = post.mock.calls[0]
    expect(url).toBe('/jadwal/9/penempatan')
    expect(body).toMatchObject({ sesi_ke: 1, jam_mulai: '08:00', jam_akhir: '09:30' })
    const semua = body.penempatan.flatMap((b: { siswa_ids: number[] }) => b.siswa_ids).sort()
    expect(semua).toEqual([1, 2, 3, 4, 5, 6])
    expect(body.penempatan).toHaveLength(2)
    expect(wrapper.emitted('saved')).toBeTruthy()
    wrapper.unmount()
  })

  it('menolak acak bila kapasitas ruang kurang dan tidak mengirim apa pun', async () => {
    mockData()
    const wrapper = mount(PenempatanRuangModal, { props: { jadwal } })
    await flushPromises()
    await centang(wrapper, '7A')
    await centang(wrapper, '7B')
    await centang(wrapper, 'R1')
    await tombol(wrapper, 'Acak Penempatan').trigger('click')

    expect(wrapper.find('[role="alert"]').text()).toContain('kurang 2')
    expect(tombol(wrapper, 'Simpan Penempatan').attributes('disabled')).toBeDefined()
    expect(post).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('penempatan manual: ruang bisa diubah per siswa dan massal, tidak boleh melebihi kapasitas', async () => {
    mockData()
    const wrapper = mount(PenempatanRuangModal, { props: { jadwal } })
    await flushPromises()
    await centang(wrapper, '7A')
    await centang(wrapper, 'R1')
    await centang(wrapper, 'R2')

    const [massal] = wrapper.findAll('select').filter((s) => s.attributes('aria-label') === 'Ruang untuk semua yang tampil')
    await massal.setValue('10')
    await tombol(wrapper, 'Terapkan').trigger('click')
    expect(wrapper.text()).toContain('R1: 4/4')

    // Pindahkan satu siswa ke R2 secara manual.
    await wrapper.find('select[aria-label="Ruang untuk Ani"]').setValue('11')
    expect(wrapper.text()).toContain('R1: 3/4')
    expect(wrapper.text()).toContain('R2: 1/4')
    expect(wrapper.text()).toContain('Penempatan siap disimpan')
    wrapper.unmount()
  })

  it('siswa yang sudah ditempatkan tidak muncul lagi dan reset memanggil endpoint', async () => {
    mockData([{ id: 1, sesi_ke: 1, peserta: [{ siswa_id: 1 }, { siswa_id: 2 }] }])
    const wrapper = mount(PenempatanRuangModal, { props: { jadwal } })
    await flushPromises()
    expect(wrapper.text()).toContain('sudah memiliki 1 sesi ruang')
    await centang(wrapper, '7A')
    expect(wrapper.text()).toContain('(2 siswa')
    expect(wrapper.text()).not.toContain('Ani')

    await tombol(wrapper, 'Reset Penempatan').trigger('click')
    await tombol(wrapper, 'Ya, reset').trigger('click')
    await flushPromises()
    expect(del).toHaveBeenCalledWith('/jadwal/9/penempatan')
    wrapper.unmount()
  })

  it('menampilkan pesan error dari server saat menyimpan', async () => {
    mockData()
    post.mockRejectedValue(new Error('Ruang R1 sudah dipakai ujian lain pada jam yang bertabrakan.'))
    const wrapper = mount(PenempatanRuangModal, { props: { jadwal } })
    await flushPromises()
    await centang(wrapper, '7A')
    await centang(wrapper, 'R1')
    await tombol(wrapper, 'Acak Penempatan').trigger('click')
    await tombol(wrapper, 'Simpan Penempatan').trigger('click')
    await flushPromises()
    expect(wrapper.find('[role="alert"]').text()).toContain('sudah dipakai ujian lain')
    expect(wrapper.emitted('saved')).toBeFalsy()
    wrapper.unmount()
  })
})

describe('PengawasSesiModal', () => {
  const sesi = { id: 5, ruang: 'R1', sesi_ke: 1, jam_mulai: '08:00', jam_akhir: '09:30', pengawas_ids: [1] }

  beforeEach(() => {
    get.mockReset().mockResolvedValue({ data: { data: [{ id: 1, user: { name: 'Bu Ani' } }, { id: 2, user: { name: 'Pak Budi' } }] } })
    post.mockReset().mockResolvedValue({ data: {} })
  })

  it('menyimpan daftar pengawas terpilih per sesi', async () => {
    const wrapper = mount(PengawasSesiModal, { props: { sesi, judul: 'UTS IPA - IPA' } })
    await flushPromises()
    const budi = wrapper.findAll('label').find((l) => l.text().includes('Pak Budi'))!
    await budi.find('input').setValue(true)
    await wrapper.findAll('button').find((b) => b.text().includes('Simpan Pengawas'))!.trigger('click')
    await flushPromises()

    expect(post).toHaveBeenCalledWith('/sesi-ruang/5/pengawas', { guru_ids: [1, 2] })
    expect(wrapper.emitted('saved')).toBeTruthy()
    wrapper.unmount()
  })

  it('menampilkan bentrok pengawas dari server tanpa menutup modal', async () => {
    post.mockRejectedValue(new Error('Pengawas Bu Ani sudah bertugas di R2 (Matematika, 09:00-10:30) pada waktu yang bertabrakan.'))
    const wrapper = mount(PengawasSesiModal, { props: { sesi, judul: 'UTS IPA - IPA' } })
    await flushPromises()
    await wrapper.findAll('button').find((b) => b.text().includes('Simpan Pengawas'))!.trigger('click')
    await flushPromises()
    expect(wrapper.find('[role="alert"]').text()).toContain('bertabrakan')
    expect(wrapper.emitted('saved')).toBeFalsy()
    wrapper.unmount()
  })

  it('mengikuti penempatan jadwal lain: kelas dan ruang terpilih, siswa tetap di ruang yang sama, siswa baru diisikan', async () => {
    mockData([], [jadwalLain, { ...jadwalLain, id: 6, sesi_ruang: [] }, { ...jadwalLain, id: 7, tahun_ajaran_id: 2 }, { ...jadwal, id: 9, sesi_ruang: [{ id: 3 }] }])
    const wrapper = mount(PenempatanRuangModal, { props: { jadwal } })
    await flushPromises()

    const opsi = wrapper.findAll('select[aria-label="Pilih jadwal sumber penempatan"] option').map((o) => o.text())
    expect(opsi).toHaveLength(2) // placeholder + 1 jadwal (tanpa sesi, tahun lain, atau jadwal ini sendiri)
    expect(opsi[1]).toContain('UTS Matematika - Matematika')

    await wrapper.find('select[aria-label="Pilih jadwal sumber penempatan"]').setValue(5)
    await tombol(wrapper, 'Terapkan').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('Penempatan mengikuti jadwal terpilih untuk 5 siswa')
    expect(wrapper.text()).toContain('1 siswa yang belum ada di sana')

    await tombol(wrapper, 'Simpan Penempatan').trigger('click')
    await flushPromises()
    const body = post.mock.calls[0][1] as { penempatan: { ruang_id: number; siswa_ids: number[] }[] }
    const peta = Object.fromEntries(body.penempatan.flatMap((p) => p.siswa_ids.map((id) => [id, p.ruang_id])))
    expect([peta[1], peta[2]]).toEqual([10, 10])
    expect([peta[3], peta[4], peta[5]]).toEqual([11, 11, 11])
    expect(Object.keys(peta)).toHaveLength(6)
    wrapper.unmount()
  })
})
