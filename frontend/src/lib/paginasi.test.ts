import { describe, expect, it } from 'vitest'
import { ambilHalaman } from './paginasi'

describe('ambilHalaman', () => {
  const data = Array.from({ length: 53 }, (_, i) => i + 1)

  it('mengambil halaman pertama dan terakhir', () => {
    const h1 = ambilHalaman(data, 1, 25)
    expect(h1).toMatchObject({ halaman: 1, totalHalaman: 3, dari: 1, sampai: 25, total: 53 })
    const h3 = ambilHalaman(data, 3, 25)
    expect(h3.item).toEqual([51, 52, 53])
    expect(h3).toMatchObject({ dari: 51, sampai: 53 })
  })

  it('menjepit halaman di luar rentang', () => {
    expect(ambilHalaman(data, 99, 25).halaman).toBe(3)
    expect(ambilHalaman(data, 0, 25).halaman).toBe(1)
    expect(ambilHalaman(data, Number.NaN, 25).halaman).toBe(1)
  })

  it('menangani daftar kosong', () => {
    expect(ambilHalaman([], 1, 25)).toMatchObject({ item: [], totalHalaman: 1, dari: 0, sampai: 0, total: 0 })
  })
})
