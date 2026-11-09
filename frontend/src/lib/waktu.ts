/** Format tanggal dan jam untuk tampilan. Nilai dari server dapat berupa ISO ("2026-11-08T00:00:00.000000Z") atau "YYYY-MM-DD". */

/** Ambil bagian tanggal "YYYY-MM-DD" tanpa konversi zona waktu perangkat. */
export function bagianTanggal(nilai: unknown): string {
  return String(nilai ?? '').slice(0, 10)
}

/** "2026-11-08T00:00:00.000000Z" -> "8 Nov 2026". Nilai tak dikenal dikembalikan apa adanya. */
export function tanggalPendek(nilai: unknown): string {
  const t = bagianTanggal(nilai)
  const cocok = /^(\d{4})-(\d{2})-(\d{2})$/.exec(t)
  if (!cocok) return String(nilai ?? '')
  const [, y, m, d] = cocok.map(Number)
  return new Date(Date.UTC(y, m - 1, d)).toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' })
}

/** "17:00:00" -> "17:00". */
export function jam(nilai: unknown): string {
  return String(nilai ?? '').slice(0, 5)
}
