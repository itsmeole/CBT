/**
 * Pemetaan kontrak API ujian siswa (bentuk payload BE <-> bentuk state UI).
 * Fungsi di sini murni (tanpa efek samping) agar mudah diuji.
 */
export type TipeSoal = 'pg' | 'pg_kompleks' | 'benar_salah' | 'menjodohkan' | 'essay'

export interface OpsiSoal {
  id: number | string
  konten: string
}

export interface SoalUjian {
  nomor: number
  soalId: number
  tipe: TipeSoal
  konten: string
  /** Untuk semua tipe selain menjodohkan. */
  opsi: OpsiSoal[]
  /** Hanya untuk menjodohkan. */
  kiri: OpsiSoal[]
  kanan: OpsiSoal[]
}

export type NilaiJawaban = string | number | (string | number)[] | Record<string, string | number> | null

interface SoalMentah {
  nomor: number
  soal_id: number
  tipe: TipeSoal
  konten: string
  opsi: OpsiSoal[] | { kiri?: OpsiSoal[]; kanan?: OpsiSoal[] }
}

export function normalisasiSoal(mentah: SoalMentah[]): SoalUjian[] {
  return mentah.map((s) => {
    const pasangan = Array.isArray(s.opsi) ? {} : s.opsi
    return {
      nomor: s.nomor,
      soalId: s.soal_id,
      tipe: s.tipe,
      konten: s.konten,
      opsi: Array.isArray(s.opsi) ? s.opsi : [],
      kiri: pasangan.kiri ?? [],
      kanan: pasangan.kanan ?? []
    }
  })
}

export function sudahDijawab(jawaban: NilaiJawaban | undefined): boolean {
  if (jawaban === undefined || jawaban === null || jawaban === '') return false
  if (typeof jawaban === 'string') return jawaban.trim() !== ''
  if (Array.isArray(jawaban)) return jawaban.length > 0
  if (typeof jawaban === 'object') return Object.keys(jawaban).length > 0
  return true
}

/** Nomor urut naik terus (juga setelah reload) karena BE menolak nomor yang lebih kecil dari yang tersimpan. */
export function nomorUrutBerikut(terakhir: number, sekarangMs: number): number {
  return Math.max(terakhir + 1, sekarangMs)
}

export interface ItemAutosave {
  soal_id: number
  jawaban: NilaiJawaban
  client_seq: number
}

/** Gabungkan antrean: hanya jawaban terbaru per soal yang dikirim (delta, FR-BE-64). */
export function susunItemAutosave(
  antrean: { soalId: string; jawaban: unknown; clientSeq: number }[]
): ItemAutosave[] {
  const terbaru = new Map<number, ItemAutosave>()
  for (const a of antrean) {
    const soalId = Number(a.soalId)
    const lama = terbaru.get(soalId)
    if (!lama || a.clientSeq > lama.client_seq) {
      terbaru.set(soalId, { soal_id: soalId, jawaban: a.jawaban as NilaiJawaban, client_seq: a.clientSeq })
    }
  }
  return [...terbaru.values()].sort((x, y) => x.client_seq - y.client_seq)
}

export type PemicuLock = 'visibilitychange' | 'pagehide' | 'blur' | 'fullscreenchange' | 'offline'
