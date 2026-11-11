import { beforeEach, describe, expect, it, vi } from 'vitest'

const { get, post } = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))

vi.mock('@/api/client', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/client')>()
  return { ...actual, apiClient: { get, post } }
})

import { ajukanLaporan, basisLaporan, jenisUntukRole, susunBodyLaporan, tungguLaporan } from './laporan'

describe('laporan', () => {
  beforeEach(() => {
    get.mockReset()
    post.mockReset()
  })

  it('menentukan basis jadwal/sesi dan hanya mengirim parameter yang relevan', () => {
    expect(basisLaporan('rekap-nilai')).toBe('jadwal')
    expect(basisLaporan('daftar-hadir')).toBe('sesi')
    expect(basisLaporan('daftar-hadir-semua')).toBe('jadwal')
    expect(susunBodyLaporan('rekap-nilai', { jadwal_id: 1, sesi_ruang_id: 9, kelas_id: 4 })).toEqual({ jadwal_id: 1, kelas_id: 4 })
    expect(susunBodyLaporan('daftar-hadir', { jadwal_id: 1, sesi_ruang_id: 9 })).toEqual({ sesi_ruang_id: 9 })
  })

  it('kartu peserta tidak ditawarkan untuk guru', () => {
    expect(jenisUntukRole('guru')).toEqual(['daftar-hadir', 'rekap-nilai'])
    expect(jenisUntukRole('operator')).toEqual(['daftar-hadir', 'daftar-hadir-semua', 'rekap-nilai'])
    expect(jenisUntukRole('operator')).toContain('daftar-hadir-semua')
    expect(jenisUntukRole('guru')).not.toContain('daftar-hadir-semua')
    expect(jenisUntukRole('siswa')).toEqual([])
  })

  it('mengajukan laporan ke endpoint sesuai jenis dan mengembalikan id', async () => {
    post.mockResolvedValue({ data: { data: { id: 12 } } })
    expect(await ajukanLaporan('daftar-hadir', { sesi_ruang_id: 3 })).toBe(12)
    expect(post).toHaveBeenCalledWith('/laporan-pdf/daftar-hadir', { sesi_ruang_id: 3 })
  })

  it('menunggu sampai PDF siap (202 lalu 200)', async () => {
    get
      .mockResolvedValueOnce({ status: 202, data: new Blob(['{}']) })
      .mockResolvedValueOnce({ status: 202, data: new Blob(['{}']) })
      .mockResolvedValueOnce({ status: 200, data: new Blob(['%PDF']) })
    const blob = await tungguLaporan(5, { intervalMs: 1 })
    expect(blob.type).toBe('application/pdf')
    expect(get).toHaveBeenCalledTimes(3)
  })

  it('melempar error saat job gagal (409) atau melewati batas waktu', async () => {
    get.mockResolvedValueOnce({ status: 409, data: new Blob(['{}']) })
    await expect(tungguLaporan(5, { intervalMs: 1 })).rejects.toMatchObject({ code: 'REPORT_FAILED' })

    get.mockReset().mockResolvedValue({ status: 202, data: new Blob(['{}']) })
    await expect(tungguLaporan(5, { intervalMs: 1, maksPercobaan: 3 })).rejects.toMatchObject({ code: 'REPORT_TIMEOUT' })
  })
})
