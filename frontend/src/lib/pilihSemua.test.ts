import { describe, expect, it } from 'vitest'
import { statusPilihan, toggleSemua } from './pilihSemua'

describe('pilihSemua', () => {
  const semua = [1, 2, 3]

  it('menentukan status kosong, sebagian, dan semua', () => {
    expect(statusPilihan(semua, [])).toBe('kosong')
    expect(statusPilihan(semua, [2])).toBe('sebagian')
    expect(statusPilihan(semua, [3, 1, 2])).toBe('semua')
    expect(statusPilihan([], [1])).toBe('kosong')
  })

  it('mengabaikan pilihan yang tidak ada lagi di daftar', () => {
    expect(statusPilihan(semua, [9])).toBe('kosong')
    expect(statusPilihan(semua, [1, 2, 3, 9])).toBe('semua')
  })

  it('toggle memilih semua bila belum penuh dan mengosongkan bila sudah penuh', () => {
    expect(toggleSemua(semua, [])).toEqual([1, 2, 3])
    expect(toggleSemua(semua, [1])).toEqual([1, 2, 3])
    expect(toggleSemua(semua, [1, 2, 3])).toEqual([])
    expect(toggleSemua([], [])).toEqual([])
  })
})
