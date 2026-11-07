import { describe, expect, it } from 'vitest'
import { labelJadwal, pilihJadwal, saringJadwal, type JadwalRingkas } from './filterJadwal'

const jadwal: JadwalRingkas[] = [
  { id: 1, nama: 'UTS IPA', mapel_id: 10, mapel: { nama: 'IPA' } },
  { id: 2, nama: 'UTS MTK', mapel_id: 20, mapel: { nama: 'Matematika' } },
  { id: 3, nama: 'UAS IPA', mapel_id: 10, mapel: { nama: 'IPA' } }
]

describe('filterJadwal', () => {
  it('menyaring jadwal berdasarkan mapel; kosong berarti semua', () => {
    expect(saringJadwal(jadwal, 10).map((j) => j.id)).toEqual([1, 3])
    expect(saringJadwal(jadwal, 20).map((j) => j.id)).toEqual([2])
    expect(saringJadwal(jadwal, '')).toHaveLength(3)
    expect(saringJadwal(jadwal, 99)).toEqual([])
  })

  it('mempertahankan jadwal terpilih bila masih tersedia, jika tidak memilih yang pertama', () => {
    const ipa = saringJadwal(jadwal, 10)
    expect(pilihJadwal(ipa, 3)).toBe(3)
    expect(pilihJadwal(ipa, 2)).toBe(1)
    expect(pilihJadwal(ipa, null)).toBe(1)
    expect(pilihJadwal([], 1)).toBeNull()
  })

  it('membuat label jadwal dengan nama mapel bila ada', () => {
    expect(labelJadwal(jadwal[0])).toBe('UTS IPA - IPA')
    expect(labelJadwal({ id: 9, nama: 'X', mapel_id: 1 })).toBe('X')
  })
})
