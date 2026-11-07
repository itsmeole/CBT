import { describe, expect, it } from 'vitest'
import { labelStatusNilai, normalisasiSkor, skorEssayValid, teksPanduan } from './koreksi'

describe('koreksi', () => {
  it('skor essay dibatasi 0..maksimal dengan maksimal 2 desimal', () => {
    expect(skorEssayValid('5', '10.00')).toBe(true)
    expect(skorEssayValid('10', '10.00')).toBe(true)
    expect(skorEssayValid('10,5', '10.00')).toBe(false)
    expect(skorEssayValid('2,5', '10.00')).toBe(true)
    expect(skorEssayValid('-1', '10.00')).toBe(false)
    expect(skorEssayValid('1.234', '10.00')).toBe(false)
    expect(skorEssayValid('abc', '10.00')).toBe(false)
    expect(skorEssayValid('', '10.00')).toBe(false)
  })

  it('menormalkan koma desimal', () => {
    expect(normalisasiSkor(' 2,5 ')).toBe('2.5')
  })

  it('merangkai kunci dari string atau struktur', () => {
    expect(teksPanduan(null)).toBe('-')
    expect(teksPanduan('Jawaban A')).toBe('Jawaban A')
    expect(teksPanduan({ a: 'x', b: ['y', 'z'] })).toBe('x; y; z')
  })

  it('label status nilai: menunggu koreksi vs final', () => {
    expect(labelStatusNilai('final', 'selesai').teks).toBe('Final')
    expect(labelStatusNilai('menunggu_koreksi', 'selesai').teks).toBe('Menunggu Koreksi Essay')
    expect(labelStatusNilai(null, 'mengerjakan').teks).toBe('Belum selesai')
  })
})
