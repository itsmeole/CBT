import { describe, expect, it } from 'vitest'
import { bagianTanggal, jam, tanggalPendek } from './waktu'

describe('waktu', () => {
  it('menyingkat tanggal ISO tanpa zona waktu', () => {
    expect(tanggalPendek('2026-11-08T00:00:00.000000Z')).toBe('8 Nov 2026')
    expect(tanggalPendek('2026-11-08')).toBe('8 Nov 2026')
    expect(bagianTanggal('2026-11-08T00:00:00.000000Z')).toBe('2026-11-08')
  })

  it('tanggal tak dikenal dikembalikan apa adanya', () => {
    expect(tanggalPendek('')).toBe('')
    expect(tanggalPendek(null)).toBe('')
  })

  it('memotong detik dari jam', () => {
    expect(jam('17:00:00')).toBe('17:00')
    expect(jam('08:30')).toBe('08:30')
  })
})
