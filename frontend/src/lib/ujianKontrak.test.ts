import { describe, expect, it } from 'vitest'
import { normalisasiSoal, nomorUrutBerikut, sudahDijawab, susunItemAutosave } from './ujianKontrak'

describe('ujianKontrak', () => {
  it('memetakan soal menjodohkan ke kiri/kanan dan soal lain ke daftar opsi', () => {
    const hasil = normalisasiSoal([
      { nomor: 1, soal_id: 10, tipe: 'pg', konten: 'a', opsi: [{ id: 'x', konten: 'A' }] },
      {
        nomor: 2,
        soal_id: 11,
        tipe: 'menjodohkan',
        konten: 'b',
        opsi: { kiri: [{ id: 'k1', konten: 'K' }], kanan: [{ id: 'r1', konten: 'R' }] }
      }
    ])
    expect(hasil[0]).toMatchObject({ soalId: 10, opsi: [{ id: 'x', konten: 'A' }], kiri: [], kanan: [] })
    expect(hasil[1]).toMatchObject({ soalId: 11, opsi: [], kiri: [{ id: 'k1', konten: 'K' }] })
  })

  it('mendeteksi jawaban kosong untuk semua bentuk', () => {
    expect(sudahDijawab(undefined)).toBe(false)
    expect(sudahDijawab('  ')).toBe(false)
    expect(sudahDijawab([])).toBe(false)
    expect(sudahDijawab({})).toBe(false)
    expect(sudahDijawab(0)).toBe(true)
    expect(sudahDijawab([1])).toBe(true)
  })

  it('nomor urut selalu naik walau jam mundur', () => {
    expect(nomorUrutBerikut(100, 50)).toBe(101)
    expect(nomorUrutBerikut(100, 500)).toBe(500)
  })

  it('menyusun item autosave: satu per soal, versi terbaru', () => {
    const items = susunItemAutosave([
      { soalId: '1', jawaban: 'a', clientSeq: 1 },
      { soalId: '2', jawaban: 'x', clientSeq: 2 },
      { soalId: '1', jawaban: 'b', clientSeq: 3 }
    ])
    expect(items).toEqual([
      { soal_id: 2, jawaban: 'x', client_seq: 2 },
      { soal_id: 1, jawaban: 'b', client_seq: 3 }
    ])
  })
})
