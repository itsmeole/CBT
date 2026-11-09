/** Logika pasang-ketuk untuk soal menjodohkan. Jawaban berupa peta: id item kiri -> id item kanan. */

export type PetaPasangan = Record<string, string | number>

const sama = (a: unknown, b: unknown): boolean => String(a) === String(b)

/** Pasangkan kiri dengan kanan. Satu item kanan hanya boleh dipakai satu item kiri, sehingga pasangan lamanya dilepas. */
export function pasangkan(peta: PetaPasangan, kiriId: string | number, kananId: string | number): PetaPasangan {
  const baru: PetaPasangan = {}
  for (const [k, v] of Object.entries(peta)) {
    if (!sama(v, kananId) && !sama(k, kiriId)) baru[k] = v
  }
  baru[String(kiriId)] = kananId
  return baru
}

/** Lepas pasangan sebuah item kiri. */
export function lepaskan(peta: PetaPasangan, kiriId: string | number): PetaPasangan {
  const baru = { ...peta }
  delete baru[String(kiriId)]
  return baru
}

/** Id item kiri yang berpasangan dengan item kanan tertentu (atau null). */
export function kiriDariKanan(peta: PetaPasangan, kananId: string | number): string | null {
  const ketemu = Object.entries(peta).find(([, v]) => sama(v, kananId))
  return ketemu ? ketemu[0] : null
}

/** Item kiri berikutnya yang belum berpasangan, mulai setelah posisi `dari` lalu memutar. Null bila semua sudah berpasangan. */
export function kiriBerikutnya(kiriIds: (string | number)[], peta: PetaPasangan, dari: string | number | null): string | null {
  const mulai = dari === null ? 0 : kiriIds.findIndex((id) => sama(id, dari)) + 1
  for (let i = 0; i < kiriIds.length; i++) {
    const id = kiriIds[(mulai + i) % kiriIds.length]
    if (!(String(id) in peta)) return String(id)
  }
  return null
}
