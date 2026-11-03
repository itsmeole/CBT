/**
 * Menghitung sisa waktu ujian untuk TAMPILAN saja.
 * Sumber kebenaran tetap `waktu_selesai` di server (FR-FE-41). Hasil ini tidak boleh dikirim ke server.
 *
 * @param waktuSelesaiMs waktu selesai dari server (epoch ms)
 * @param nowMs waktu perangkat saat ini (epoch ms)
 * @param offsetMs selisih (waktu server - waktu perangkat) hasil sinkronisasi GET /time
 */
export function sisaDetik(waktuSelesaiMs: number, nowMs: number, offsetMs: number): number {
  const sisaMs = waktuSelesaiMs - (nowMs + offsetMs)
  return Math.max(0, Math.ceil(sisaMs / 1000))
}

export function hitungOffset(waktuServerMs: number, waktuPerangkatMs: number): number {
  return waktuServerMs - waktuPerangkatMs
}

export function formatDurasi(totalDetik: number): string {
  const jam = Math.floor(totalDetik / 3600)
  const menit = Math.floor((totalDetik % 3600) / 60)
  const detik = totalDetik % 60
  const dua = (n: number) => String(n).padStart(2, '0')
  return jam > 0 ? `${dua(jam)}:${dua(menit)}:${dua(detik)}` : `${dua(menit)}:${dua(detik)}`
}

