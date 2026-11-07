export interface HasilHalaman<T> {
  item: T[]
  halaman: number
  totalHalaman: number
  dari: number
  sampai: number
  total: number
}

/** Paginasi sisi klien. `halaman` dijepit ke rentang valid agar tidak kosong setelah filter berubah. */
export function ambilHalaman<T>(daftar: readonly T[], halaman: number, ukuran: number): HasilHalaman<T> {
  const total = daftar.length
  const totalHalaman = Math.max(1, Math.ceil(total / ukuran))
  const aktif = Math.min(Math.max(1, Math.floor(halaman) || 1), totalHalaman)
  const awal = (aktif - 1) * ukuran
  const item = daftar.slice(awal, awal + ukuran)
  return {
    item,
    halaman: aktif,
    totalHalaman,
    dari: total === 0 ? 0 : awal + 1,
    sampai: awal + item.length,
    total
  }
}
