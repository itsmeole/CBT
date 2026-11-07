/** Tampilkan kunci/rubrik essay (string atau struktur JSON) sebagai teks biasa. */
export function teksPanduan(nilai: unknown): string {
  if (nilai === null || nilai === undefined || nilai === '') return '-'
  if (typeof nilai === 'string') return nilai
  if (Array.isArray(nilai)) return nilai.map(teksPanduan).join('; ')
  if (typeof nilai === 'object') {
    return Object.values(nilai as Record<string, unknown>)
      .map(teksPanduan)
      .join('; ')
  }
  return String(nilai)
}

/** Skor essay harus angka 0..maksimal dengan paling banyak 2 desimal (selaras validasi BE). */
export function skorEssayValid(input: string, maksimal: string | number): boolean {
  const teks = input.trim().replace(',', '.')
  if (!/^\d+(\.\d{1,2})?$/.test(teks)) return false
  return Number(teks) >= 0 && Number(teks) <= Number(maksimal)
}

export function normalisasiSkor(input: string): string {
  return input.trim().replace(',', '.')
}

export type StatusNilai = 'menunggu_koreksi' | 'final' | string | null

export function labelStatusNilai(status: StatusNilai, statusPeserta: string): { teks: string; kelas: string } {
  if (statusPeserta !== 'selesai') return { teks: 'Belum selesai', kelas: 'bg-slate-100 text-slate-600' }
  if (status === 'final') return { teks: 'Final', kelas: 'bg-emerald-100 text-emerald-800' }
  if (status === null) return { teks: 'Diproses', kelas: 'bg-sky-100 text-sky-800' }
  return { teks: 'Menunggu Koreksi Essay', kelas: 'bg-amber-100 text-amber-800' }
}
