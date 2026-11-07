import { describe, expect, it } from 'vitest'
import { kategoriDayaPembeda, kategoriKesukaran, persenBar } from './analisis'

describe('analisis butir', () => {
  it('mengkategorikan indeks kesukaran', () => {
    expect(kategoriKesukaran(0.85).teks).toBe('Mudah')
    expect(kategoriKesukaran(0.7).teks).toBe('Sedang')
    expect(kategoriKesukaran(0.3).teks).toBe('Sedang')
    expect(kategoriKesukaran(0.29).teks).toBe('Sulit')
    expect(kategoriKesukaran(null).teks).toBe('-')
  })

  it('mengkategorikan daya pembeda termasuk negatif dan data kurang', () => {
    expect(kategoriDayaPembeda(0.45).teks).toBe('Sangat baik')
    expect(kategoriDayaPembeda(0.3).teks).toBe('Baik')
    expect(kategoriDayaPembeda(0.2).teks).toBe('Cukup')
    expect(kategoriDayaPembeda(0.05).teks).toBe('Perlu direvisi')
    expect(kategoriDayaPembeda(-0.1).teks).toBe('Buruk (negatif)')
    expect(kategoriDayaPembeda(null).teks).toBe('Data kurang')
  })

  it('menjepit persen bar ke 0..100', () => {
    expect(persenBar(0.456)).toBe(46)
    expect(persenBar(1.5)).toBe(100)
    expect(persenBar(-0.2)).toBe(0)
    expect(persenBar(null)).toBe(0)
  })
})
