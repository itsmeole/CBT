import { describe, expect, it } from 'vitest'
import { acakPenempatan, kocok, ringkasPerRuang, susunPayloadPenempatan, validasiPenempatan } from './penempatan'

const ruang = [
  { id: 1, nama: 'R1', kapasitas: 4 },
  { id: 2, nama: 'R2', kapasitas: 4 },
  { id: 3, nama: 'R3', kapasitas: 2 }
]
const siswa = [10, 11, 12, 13, 14, 15, 16, 17, 18]

/** Sumber acak deterministik (LCG) agar tes stabil. */
function rngTetap(seed = 42) {
  let s = seed
  return () => {
    s = (s * 1664525 + 1013904223) % 4294967296
    return s / 4294967296
  }
}

describe('penempatan ruang', () => {
  it('mengocok tanpa kehilangan atau menggandakan elemen', () => {
    const hasil = kocok(siswa, rngTetap())
    expect([...hasil].sort((a, b) => a - b)).toEqual(siswa)
    expect(hasil).not.toEqual(siswa)
  })

  it('acak: semua siswa ditempatkan, merata, dan tidak melebihi kapasitas', () => {
    const { peta, error } = acakPenempatan(siswa, ruang, rngTetap())
    expect(error).toBeUndefined()
    expect(Object.keys(peta)).toHaveLength(9)
    const per = ringkasPerRuang(peta, ruang).map((r) => r.jumlah)
    // R3 hanya berkapasitas 2, sisanya dibagi serata mungkin ke R1 dan R2.
    expect(per).toEqual([4, 3, 2])
    expect(per.reduce((a, b) => a + b, 0)).toBe(9)
    expect(ringkasPerRuang(peta, ruang).every((r) => !r.melebihi)).toBe(true)
  })

  it('acak: ruang kecil tidak diisi melebihi kapasitas, sisa dialihkan ke ruang lain', () => {
    const { peta } = acakPenempatan([1, 2, 3, 4, 5, 6, 7, 8, 9, 10], [{ id: 1, kapasitas: 2 }, { id: 2, kapasitas: 10 }], rngTetap())
    const per = ringkasPerRuang(peta, [{ id: 1, kapasitas: 2 }, { id: 2, kapasitas: 10 }])
    expect(per[0].jumlah).toBeLessThanOrEqual(2)
    expect(per[0].jumlah + per[1].jumlah).toBe(10)
  })

  it('acak: hasil berbeda untuk seed berbeda tetapi sama untuk seed yang sama', () => {
    const a = acakPenempatan(siswa, ruang, rngTetap(1)).peta
    const b = acakPenempatan(siswa, ruang, rngTetap(1)).peta
    const c = acakPenempatan(siswa, ruang, rngTetap(2)).peta
    expect(a).toEqual(b)
    expect(a).not.toEqual(c)
  })

  it('acak: gagal dengan pesan jelas bila kapasitas kurang atau ruang kosong', () => {
    const kurang = acakPenempatan(siswa, [{ id: 1, kapasitas: 5 }])
    expect(kurang.error).toContain('kurang 4')
    expect(kurang.peta).toEqual({})
    expect(acakPenempatan(siswa, []).error).toContain('minimal satu ruang')
  })

  it('validasi: menolak siswa belum ditempatkan dan ruang melebihi kapasitas', () => {
    const peta = { 10: 3, 11: 3, 12: 3 }
    expect(validasiPenempatan(peta, [10, 11, 12], ruang)).toEqual({ ok: false, alasan: 'Ruang R3 melebihi kapasitas (3/2).' })
    expect(validasiPenempatan({ 10: 1 }, [10, 11], ruang).alasan).toBe('1 siswa belum ditempatkan di ruang.')
    expect(validasiPenempatan({}, [], ruang).ok).toBe(false)
    expect(validasiPenempatan({ 10: 1, 11: 2 }, [10, 11], ruang).ok).toBe(true)
  })

  it('menyusun payload per ruang dan melewati ruang kosong', () => {
    const payload = susunPayloadPenempatan({ 10: 1, 11: 1, 12: 3 }, ruang)
    expect(payload).toEqual([
      { ruang_id: 1, siswa_ids: [10, 11] },
      { ruang_id: 3, siswa_ids: [12] }
    ])
  })
})
