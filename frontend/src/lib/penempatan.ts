export interface RuangKapasitas {
  id: number
  nama?: string
  kapasitas: number
}

/** Penempatan: id siswa -> id ruang (siswa tanpa entri berarti belum ditempatkan). */
export type PetaPenempatan = Record<number, number>

export interface HasilAcak {
  peta: PetaPenempatan
  error?: string
}

/** Fisher-Yates dengan sumber acak yang bisa disuntikkan agar dapat diuji. */
export function kocok<T>(daftar: readonly T[], rng: () => number = Math.random): T[] {
  const hasil = [...daftar]
  for (let i = hasil.length - 1; i > 0; i--) {
    const j = Math.floor(rng() * (i + 1))
    ;[hasil[i], hasil[j]] = [hasil[j], hasil[i]]
  }
  return hasil
}

/**
 * Bagikan siswa secara acak ke ruang yang dipilih. Jumlah per ruang dibuat serata mungkin
 * (satu per satu bergiliran) dan tidak pernah melebihi kapasitas ruang.
 */
export function acakPenempatan(siswaIds: readonly number[], ruang: readonly RuangKapasitas[], rng: () => number = Math.random): HasilAcak {
  if (ruang.length === 0) return { peta: {}, error: 'Pilih minimal satu ruang.' }
  const totalKapasitas = ruang.reduce((jumlah, r) => jumlah + r.kapasitas, 0)
  if (siswaIds.length > totalKapasitas) {
    return {
      peta: {},
      error: `Kapasitas ruang terpilih ${totalKapasitas} kursi, kurang ${siswaIds.length - totalKapasitas} untuk ${siswaIds.length} siswa. Tambah ruang.`
    }
  }

  const jatah = new Map<number, number>(ruang.map((r) => [r.id, 0]))
  let sisa = siswaIds.length
  while (sisa > 0) {
    for (const r of ruang) {
      if (sisa === 0) break
      if ((jatah.get(r.id) ?? 0) < r.kapasitas) {
        jatah.set(r.id, (jatah.get(r.id) ?? 0) + 1)
        sisa--
      }
    }
  }

  const peta: PetaPenempatan = {}
  const acak = kocok(siswaIds, rng)
  let posisi = 0
  for (const r of ruang) {
    for (let i = 0; i < (jatah.get(r.id) ?? 0); i++) {
      peta[acak[posisi++]] = r.id
    }
  }
  return { peta }
}

export interface RingkasanRuang {
  ruangId: number
  nama: string
  jumlah: number
  kapasitas: number
  melebihi: boolean
}

export function ringkasPerRuang(peta: PetaPenempatan, ruang: readonly RuangKapasitas[]): RingkasanRuang[] {
  return ruang.map((r) => {
    const jumlah = Object.values(peta).filter((ruangId) => ruangId === r.id).length
    return { ruangId: r.id, nama: r.nama ?? `Ruang ${r.id}`, jumlah, kapasitas: r.kapasitas, melebihi: jumlah > r.kapasitas }
  })
}

export interface HasilValidasi {
  ok: boolean
  alasan?: string
}

/** Penempatan siap disimpan bila semua siswa punya ruang dan tidak ada ruang yang melebihi kapasitas. */
export function validasiPenempatan(peta: PetaPenempatan, siswaIds: readonly number[], ruang: readonly RuangKapasitas[]): HasilValidasi {
  if (siswaIds.length === 0) return { ok: false, alasan: 'Belum ada peserta yang dipilih.' }
  const belum = siswaIds.filter((id) => peta[id] === undefined).length
  if (belum > 0) return { ok: false, alasan: `${belum} siswa belum ditempatkan di ruang.` }
  const penuh = ringkasPerRuang(peta, ruang).find((r) => r.melebihi)
  if (penuh) return { ok: false, alasan: `Ruang ${penuh.nama} melebihi kapasitas (${penuh.jumlah}/${penuh.kapasitas}).` }
  return { ok: true }
}

export interface BarisPenempatan {
  ruang_id: number
  siswa_ids: number[]
}

/** Susun payload API; ruang tanpa peserta dilewati. */
export function susunPayloadPenempatan(peta: PetaPenempatan, ruang: readonly RuangKapasitas[]): BarisPenempatan[] {
  return ruang
    .map((r) => ({
      ruang_id: r.id,
      siswa_ids: Object.entries(peta)
        .filter(([, ruangId]) => ruangId === r.id)
        .map(([siswaId]) => Number(siswaId))
    }))
    .filter((baris) => baris.siswa_ids.length > 0)
}

export interface HasilSalin {
  peta: PetaPenempatan
  /** Siswa yang tidak ada di penempatan sumber lalu diisikan ke ruang yang masih punya kursi. */
  tambahan: number
  /** Siswa yang tidak mendapat ruang karena kursi habis. */
  tanpaRuang: number
}

/**
 * Ikuti penempatan jadwal lain: siswa tetap di ruang yang sama selama ruang itu dipilih dan masih ada kursinya.
 * Siswa yang belum ada di sumber (mis. siswa baru) diisikan ke ruang dengan sisa kursi terbanyak.
 */
export function salinPenempatan(sumber: PetaPenempatan, siswaIds: readonly number[], ruang: readonly RuangKapasitas[]): HasilSalin {
  const kapasitas = new Map<number, number>(ruang.map((r) => [r.id, r.kapasitas]))
  const terisi = new Map<number, number>(ruang.map((r) => [r.id, 0]))
  const peta: PetaPenempatan = {}
  const belum: number[] = []

  for (const id of siswaIds) {
    const asal = sumber[id]
    if (asal !== undefined && kapasitas.has(asal) && (terisi.get(asal) ?? 0) < (kapasitas.get(asal) ?? 0)) {
      peta[id] = asal
      terisi.set(asal, (terisi.get(asal) ?? 0) + 1)
    } else {
      belum.push(id)
    }
  }

  let tambahan = 0
  let tanpaRuang = 0
  for (const id of belum) {
    let terbaik: number | null = null
    let sisaTerbaik = 0
    for (const r of ruang) {
      const sisa = r.kapasitas - (terisi.get(r.id) ?? 0)
      if (sisa > sisaTerbaik) {
        sisaTerbaik = sisa
        terbaik = r.id
      }
    }
    if (terbaik === null) {
      tanpaRuang++
      continue
    }
    peta[id] = terbaik
    terisi.set(terbaik, (terisi.get(terbaik) ?? 0) + 1)
    tambahan++
  }

  return { peta, tambahan, tanpaRuang }
}
