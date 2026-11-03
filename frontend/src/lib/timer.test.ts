import { describe, expect, it } from 'vitest'
import { formatDurasi, hitungOffset, sisaDetik } from './timer'

describe('sisaDetik', () => {
  it('menghitung sisa waktu dari waktu_selesai server', () => {
    expect(sisaDetik(100_000, 40_000, 0)).toBe(60)
  })

  it('memperhitungkan offset jam perangkat yang lebih lambat dari server', () => {
    // Perangkat tertinggal 5 detik dari server: server sudah di 45_000 saat perangkat 40_000.
    const offset = hitungOffset(45_000, 40_000)
    expect(sisaDetik(100_000, 40_000, offset)).toBe(55)
  })

  it('tidak pernah negatif', () => {
    expect(sisaDetik(1_000, 50_000, 0)).toBe(0)
  })

  it('membulatkan ke atas agar 0 hanya tampil saat benar-benar habis', () => {
    expect(sisaDetik(10_500, 10_000, 0)).toBe(1)
  })
})

describe('formatDurasi', () => {
  it('format menit:detik di bawah satu jam', () => {
    expect(formatDurasi(125)).toBe('02:05')
  })

  it('format jam:menit:detik bila lebih dari satu jam', () => {
    expect(formatDurasi(3725)).toBe('01:02:05')
  })
})

