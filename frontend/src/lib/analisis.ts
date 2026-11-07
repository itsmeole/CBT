/** Kategori indeks kesukaran (p): proporsi skor rata-rata terhadap skor maksimal. */
export function kategoriKesukaran(p: number | null): { teks: string; kelas: string } {
  if (p === null) return { teks: '-', kelas: 'bg-slate-100 text-slate-600' }
  if (p > 0.7) return { teks: 'Mudah', kelas: 'bg-emerald-100 text-emerald-800' }
  if (p >= 0.3) return { teks: 'Sedang', kelas: 'bg-sky-100 text-sky-800' }
  return { teks: 'Sulit', kelas: 'bg-red-100 text-red-800' }
}

/** Kategori daya pembeda (D) menurut kriteria umum: >=0,40 sangat baik; 0,30 baik; 0,20 cukup; <0,20 buruk. */
export function kategoriDayaPembeda(d: number | null): { teks: string; kelas: string } {
  if (d === null) return { teks: 'Data kurang', kelas: 'bg-slate-100 text-slate-600' }
  if (d >= 0.4) return { teks: 'Sangat baik', kelas: 'bg-emerald-100 text-emerald-800' }
  if (d >= 0.3) return { teks: 'Baik', kelas: 'bg-emerald-50 text-emerald-700' }
  if (d >= 0.2) return { teks: 'Cukup', kelas: 'bg-amber-100 text-amber-800' }
  if (d >= 0) return { teks: 'Perlu direvisi', kelas: 'bg-orange-100 text-orange-800' }
  return { teks: 'Buruk (negatif)', kelas: 'bg-red-100 text-red-800' }
}

/** Lebar bar 0..100 untuk visualisasi; nilai di luar rentang dijepit. */
export function persenBar(nilai: number | null): number {
  if (nilai === null || Number.isNaN(nilai)) return 0
  return Math.round(Math.min(1, Math.max(0, nilai)) * 100)
}
