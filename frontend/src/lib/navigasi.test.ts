import { describe, expect, it } from 'vitest'
import { TAB_JADWAL, TAB_MASTER, anakAktif, buatMenu, itemAktif, tabValid } from './navigasi'

describe('navigasi staf', () => {
  it('Master Data dan Jadwal & Paket Ujian berupa dropdown dengan sub-opsi', () => {
    const menu = buatMenu('operator')
    const master = menu.find((m) => m.nama === 'Master Data')!
    const jadwal = menu.find((m) => m.nama === 'Jadwal & Paket Ujian')!
    expect(master.anak?.map((a) => a.nama)).toEqual(
      ['Siswa', 'Guru', 'Pengampu Mapel', 'Kelas', 'Mata Pelajaran', 'Ruang Ujian', 'Tahun Ajaran']
    )
    expect(jadwal.anak?.map((a) => a.tab)).toEqual(['jadwal', 'paket', 'bobot'])
  })

  it('guru tidak melihat Master Data, Kontrol Sesi, dan Audit Log', () => {
    const nama = buatMenu('guru').map((m) => m.nama)
    expect(nama).not.toContain('Master Data')
    expect(nama).not.toContain('Kontrol Sesi')
    expect(nama).not.toContain('Audit Log')
    expect(nama).toContain('Jadwal & Paket Ujian')
    expect(nama).toContain('Analisis Butir')
  })

  it('operator tidak melihat Analisis Butir; superadmin melihat Audit Log', () => {
    expect(buatMenu('operator').map((m) => m.nama)).not.toContain('Analisis Butir')
    expect(buatMenu('superadmin').map((m) => m.nama)).toContain('Audit Log')
  })

  it('menentukan item dan sub-menu aktif dari path dan query tab', () => {
    const master = buatMenu('operator').find((m) => m.nama === 'Master Data')!
    expect(itemAktif(master, '/staf/master')).toBe(true)
    expect(itemAktif(master, '/staf/bank-soal')).toBe(false)
    expect(anakAktif(master, TAB_MASTER[1], '/staf/master', 'guru')).toBe(true)
    expect(anakAktif(master, TAB_MASTER[0], '/staf/master', 'guru')).toBe(false)
    // Tanpa query atau tab tak dikenal -> sub-menu pertama aktif.
    expect(anakAktif(master, TAB_MASTER[0], '/staf/master', undefined)).toBe(true)
    expect(anakAktif(master, TAB_MASTER[0], '/staf/master', 'ngawur')).toBe(true)
    expect(anakAktif(master, TAB_MASTER[0], '/staf/jadwal', 'siswa')).toBe(false)
  })

  it('beranda hanya aktif pada path persis', () => {
    const ringkasan = buatMenu('guru')[0]
    expect(itemAktif(ringkasan, '/staf')).toBe(true)
    expect(itemAktif(ringkasan, '/staf/master')).toBe(false)
  })

  it('tabValid menolak tab asing', () => {
    expect(tabValid(TAB_JADWAL, 'paket')).toBe('paket')
    expect(tabValid(TAB_JADWAL, 'hapus-semua')).toBe('jadwal')
    expect(tabValid(TAB_JADWAL, undefined)).toBe('jadwal')
  })
})

describe('ikon menu', () => {
  it('setiap sub-opsi dropdown punya ikon SVG yang terdefinisi dan unik', async () => {
    const { PATH_IKON } = await import('./navigasi')
    const semuaAnak = [...TAB_MASTER, ...TAB_JADWAL]
    for (const anak of semuaAnak) {
      expect(PATH_IKON[anak.ikon], `ikon untuk ${anak.nama}`).toMatch(/^[Mm][\d\s.,a-zA-Z-]+$/)
    }
    const dipakai = semuaAnak.map((a) => a.ikon)
    expect(new Set(dipakai).size).toBe(dipakai.length)
  })

  it('setiap menu utama punya ikon terdefinisi', async () => {
    const { PATH_IKON } = await import('./navigasi')
    for (const item of buatMenu('superadmin')) {
      expect(PATH_IKON[item.ikon], `ikon untuk ${item.nama}`).toBeTruthy()
    }
  })
})
