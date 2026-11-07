import { apiClient, ApiError } from '@/api/client'
import type { Role } from '@/types/auth'
import { unduhBlob } from './download'

export type JenisLaporan = 'kartu-peserta' | 'daftar-hadir' | 'berita-acara' | 'rekap-nilai'

export const LABEL_LAPORAN: Record<JenisLaporan, string> = {
  'kartu-peserta': 'Kartu Peserta Ujian',
  'daftar-hadir': 'Daftar Hadir',
  'berita-acara': 'Berita Acara',
  'rekap-nilai': 'Rekap Nilai'
}

/** Jenis yang diminta per jadwal ujian atau per sesi ruang (selaras validasi BE). */
export function basisLaporan(jenis: JenisLaporan): 'jadwal' | 'sesi' {
  return jenis === 'kartu-peserta' || jenis === 'rekap-nilai' ? 'jadwal' : 'sesi'
}

/** Kartu peserta hanya Operator/Superadmin; scope sisanya ditegakkan server (pengawas/guru pengampu). */
export function jenisUntukRole(role: Role | null): JenisLaporan[] {
  if (role === 'operator' || role === 'superadmin') return ['kartu-peserta', 'daftar-hadir', 'berita-acara', 'rekap-nilai']
  if (role === 'guru') return ['daftar-hadir', 'berita-acara', 'rekap-nilai']
  return []
}

export interface ParameterLaporan {
  jadwal_id?: number
  sesi_ruang_id?: number
  kelas_id?: number
}

/** BE menolak parameter yang tidak relevan (`prohibited`), jadi hanya kirim yang sesuai jenis. */
export function susunBodyLaporan(jenis: JenisLaporan, param: ParameterLaporan): ParameterLaporan {
  const body: ParameterLaporan = {}
  if (basisLaporan(jenis) === 'jadwal') body.jadwal_id = param.jadwal_id
  else body.sesi_ruang_id = param.sesi_ruang_id
  if (param.kelas_id) body.kelas_id = param.kelas_id
  return body
}

export async function ajukanLaporan(jenis: JenisLaporan, param: ParameterLaporan): Promise<number> {
  const res = await apiClient.post(`/laporan-pdf/${jenis}`, susunBodyLaporan(jenis, param))
  return res.data.data.id as number
}

export interface OpsiTunggu {
  intervalMs?: number
  maksPercobaan?: number
  onPercobaan?: (ke: number) => void
}

/**
 * Pantau job PDF sampai selesai lalu kembalikan Blob.
 * BE: 202 = masih diproses, 409 = gagal, 200 = file PDF.
 */
export async function tungguLaporan(id: number, opsi: OpsiTunggu = {}): Promise<Blob> {
  const { intervalMs = 1500, maksPercobaan = 80, onPercobaan } = opsi
  for (let ke = 1; ke <= maksPercobaan; ke++) {
    onPercobaan?.(ke)
    const res = await apiClient.get(`/laporan-pdf/${id}`, {
      responseType: 'blob',
      validateStatus: (s) => s === 200 || s === 202 || s === 409
    })
    if (res.status === 200) return new Blob([res.data], { type: 'application/pdf' })
    if (res.status === 409) throw new ApiError('REPORT_FAILED', 'Pembuatan PDF gagal. Coba ajukan ulang.', 409)
    await new Promise((resolve) => setTimeout(resolve, intervalMs))
  }
  throw new ApiError('REPORT_TIMEOUT', 'PDF belum selesai dibuat. Pastikan worker antrean `reports` berjalan.', null)
}

export async function cetakLaporan(jenis: JenisLaporan, param: ParameterLaporan, opsi?: OpsiTunggu): Promise<void> {
  const id = await ajukanLaporan(jenis, param)
  const blob = await tungguLaporan(id, opsi)
  unduhBlob(blob, `${jenis}.pdf`)
}
