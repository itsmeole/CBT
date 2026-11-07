import { apiClient, ApiError } from '@/api/client'
import {
  ambilJawabanBerkey,
  ambilLockBerkey,
  antrekanLock,
  hapusJawaban,
  hapusLock
} from './offlineQueue'
import { susunItemAutosave, type PemicuLock } from './ujianKontrak'

export type HasilSinkron = 'ok' | 'offline' | 'terkunci' | 'selesai'

/** Fetch keepalive agar permintaan tetap terkirim saat halaman disembunyikan/ditutup (FR-FE-51). */
const KEEPALIVE = { adapter: 'fetch', fetchOptions: { keepalive: true } } as const

/** Kirim sinyal lock seketika; bila gagal karena offline, simpan ke antrean agar dikirim lebih dulu (FR-FE-52). */
export async function kirimLock(pemicu: PemicuLock): Promise<void> {
  try {
    await apiClient.post('/ujian/lock', { pemicu }, KEEPALIVE)
  } catch (error) {
    if (error instanceof ApiError && error.isNetworkError) {
      await antrekanLock({ pemicu, waktuMs: Date.now() })
    }
  }
}

function klasifikasi(error: unknown): HasilSinkron | 'permanen' {
  if (error instanceof ApiError) {
    if (error.code === 'SESSION_LOCKED') return 'terkunci'
    if (error.code === 'ALREADY_SUBMITTED' || error.code === 'TIME_UP') return 'selesai'
    if (error.isNetworkError || (error.status !== null && error.status >= 500)) return 'offline'
  }
  return 'permanen'
}

let sedangMengirim = false

/**
 * Kosongkan antrean: event lock lebih dulu, baru jawaban. Item dihapus hanya setelah server
 * mengonfirmasi, atau menolak permanen (4xx) agar antrean tidak macet.
 */
export async function kosongkanAntrean(): Promise<HasilSinkron> {
  if (sedangMengirim) return 'ok'
  sedangMengirim = true
  try {
    const locks = await ambilLockBerkey()
    if (locks.length > 0) {
      try {
        await apiClient.post('/ujian/lock', { pemicu: locks[0].item.pemicu }, KEEPALIVE)
        await hapusLock(locks.map((l) => l.key))
      } catch (error) {
        const hasil = klasifikasi(error)
        if (hasil === 'offline') return 'offline'
        await hapusLock(locks.map((l) => l.key))
        if (hasil === 'selesai') return 'selesai'
      }
    }

    const jawaban = await ambilJawabanBerkey()
    if (jawaban.length === 0) return 'ok'

    try {
      await apiClient.put('/ujian/jawaban', { items: susunItemAutosave(jawaban.map((j) => j.item)) })
      await hapusJawaban(jawaban.map((j) => j.key))
      return 'ok'
    } catch (error) {
      const hasil = klasifikasi(error)
      if (hasil === 'offline') return 'offline'
      // Saat terkunci jawaban dipertahankan agar terkirim setelah pengawas membuka kunci.
      if (hasil === 'terkunci') return 'terkunci'
      await hapusJawaban(jawaban.map((j) => j.key))
      return hasil === 'selesai' ? 'selesai' : 'ok'
    }
  } finally {
    sedangMengirim = false
  }
}
