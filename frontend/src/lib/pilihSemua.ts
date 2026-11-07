export type StatusPilihan = 'kosong' | 'sebagian' | 'semua'

/** Keadaan kotak "pilih semua": kosong, sebagian (indeterminate), atau semua terpilih. */
export function statusPilihan(semua: readonly number[], terpilih: readonly number[]): StatusPilihan {
  if (semua.length === 0) return 'kosong'
  const jumlah = semua.filter((id) => terpilih.includes(id)).length
  if (jumlah === 0) return 'kosong'
  return jumlah === semua.length ? 'semua' : 'sebagian'
}

/**
 * Klik "pilih semua": bila belum semua terpilih, pilih semuanya; bila sudah semua, kosongkan.
 * Pilihan di luar daftar (tidak ada lagi) dibuang.
 */
export function toggleSemua(semua: readonly number[], terpilih: readonly number[]): number[] {
  return statusPilihan(semua, terpilih) === 'semua' ? [] : [...semua]
}
