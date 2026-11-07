export interface JadwalRingkas {
  id: number
  nama: string
  mapel_id: number
  mapel?: { nama: string } | null
}

/** Jadwal yang ditampilkan untuk mapel terpilih (kosong = semua mapel yang tersedia bagi pengguna). */
export function saringJadwal(jadwal: readonly JadwalRingkas[], mapelId: number | ''): JadwalRingkas[] {
  return mapelId === '' ? [...jadwal] : jadwal.filter((j) => j.mapel_id === mapelId)
}

/** Pertahankan jadwal terpilih bila masih ada di daftar; jika tidak, ambil yang pertama (atau null). */
export function pilihJadwal(daftar: readonly JadwalRingkas[], saatIni: number | null): number | null {
  if (saatIni !== null && daftar.some((j) => j.id === saatIni)) return saatIni
  return daftar[0]?.id ?? null
}

export function labelJadwal(j: JadwalRingkas): string {
  return j.mapel?.nama ? `${j.nama} - ${j.mapel.nama}` : j.nama
}
