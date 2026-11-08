import { describe, expect, it } from 'vitest'
import {
  aksiSensitif,
  labelAksi,
  labelHari,
  persen,
  ringkasKesiapan,
  sapaan,
  selisihHari,
  statusBackup,
  waktuRelatif,
  type KesiapanJadwal
} from './dashboard'

const sekarang = new Date('2026-11-08T10:00:00Z')

describe('dashboard helper', () => {
  it('sapaan menurut jam', () => {
    expect(sapaan(7)).toBe('Selamat pagi')
    expect(sapaan(12)).toBe('Selamat siang')
    expect(sapaan(16)).toBe('Selamat sore')
    expect(sapaan(21)).toBe('Selamat malam')
  })

  it('selisih hari kalender tidak terpengaruh zona waktu', () => {
    expect(selisihHari('2026-11-08', '2026-11-09')).toBe(1)
    expect(selisihHari('2026-11-08', '2026-11-08')).toBe(0)
    expect(selisihHari('2026-11-08', '2026-10-31')).toBe(-8)
    expect(selisihHari('2026-12-31', '2027-01-01')).toBe(1)
  })

  it('label hari memakai tanggal server sebagai acuan', () => {
    expect(labelHari('2026-11-08', '2026-11-08')).toBe('Hari ini')
    expect(labelHari('2026-11-09', '2026-11-08')).toBe('Besok')
    expect(labelHari('2026-11-07', '2026-11-08')).toBe('Kemarin')
    expect(labelHari('2026-11-20', '2026-11-08')).toMatch(/20/)
    expect(labelHari('2026-11-20', '2026-11-08')).toMatch(/Nov/i)
  })

  it('waktu relatif', () => {
    expect(waktuRelatif(null, sekarang)).toBe('-')
    expect(waktuRelatif('2026-11-08T09:59:40Z', sekarang)).toBe('baru saja')
    expect(waktuRelatif('2026-11-08T09:45:00Z', sekarang)).toBe('15 menit lalu')
    expect(waktuRelatif('2026-11-08T07:00:00Z', sekarang)).toBe('3 jam lalu')
    expect(waktuRelatif('2026-11-05T10:00:00Z', sekarang)).toBe('3 hari lalu')
  })

  it('status backup: sehat < 36 jam, peringatan setelahnya, bahaya bila belum ada', () => {
    expect(statusBackup(null, sekarang).nada).toBe('bahaya')
    expect(statusBackup('2026-11-08T02:00:00Z', sekarang).nada).toBe('baik')
    expect(statusBackup('2026-11-06T10:00:00Z', sekarang).nada).toBe('peringatan')
  })

  it('label aksi audit dengan cadangan ke kode mentah', () => {
    expect(labelAksi('ujian.unlock')).toBe('Membuka kunci siswa')
    expect(labelAksi('aksi.baru')).toBe('aksi.baru')
    expect(aksiSensitif('auth.reset_login')).toBe(true)
    expect(aksiSensitif('auth.login')).toBe(false)
  })

  it('merangkum kesiapan dan persen dengan aman', () => {
    const daftar = [{ siap: true }, { siap: false }, { siap: false }] as KesiapanJadwal[]
    expect(ringkasKesiapan(daftar)).toEqual({ siap: 1, bermasalah: 2 })
    expect(persen(1, 4)).toBe(25)
    expect(persen(5, 0)).toBe(0)
    expect(persen(9, 4)).toBe(100)
  })
})
