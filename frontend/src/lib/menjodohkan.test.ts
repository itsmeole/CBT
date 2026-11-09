import { describe, expect, it } from 'vitest'
import { kiriBerikutnya, kiriDariKanan, lepaskan, pasangkan } from './menjodohkan'

describe('menjodohkan pasang-ketuk', () => {
  it('memasangkan dan menggantikan pasangan lama pada item kiri yang sama', () => {
    expect(pasangkan({}, 1, 'x')).toEqual({ '1': 'x' })
    expect(pasangkan({ '1': 'x' }, 1, 'y')).toEqual({ '1': 'y' })
  })

  it('satu item kanan hanya untuk satu item kiri (dipindahkan, bukan ganda)', () => {
    expect(pasangkan({ '1': 'x', '2': 'y' }, 2, 'x')).toEqual({ '2': 'x' })
  })

  it('melepas pasangan', () => {
    expect(lepaskan({ '1': 'x', '2': 'y' }, 1)).toEqual({ '2': 'y' })
    expect(lepaskan({}, 1)).toEqual({})
  })

  it('mencari kiri dari kanan', () => {
    expect(kiriDariKanan({ '1': 'x' }, 'x')).toBe('1')
    expect(kiriDariKanan({ '1': 'x' }, 'z')).toBeNull()
  })

  it('memilih item kiri berikutnya yang belum berpasangan, memutar ke awal', () => {
    expect(kiriBerikutnya([1, 2, 3], { '1': 'x' }, 1)).toBe('2')
    expect(kiriBerikutnya([1, 2, 3], { '1': 'x', '3': 'z' }, 3)).toBe('2')
    expect(kiriBerikutnya([1, 2, 3], { '1': 'x', '2': 'y', '3': 'z' }, 3)).toBeNull()
    expect(kiriBerikutnya([1, 2], {}, null)).toBe('1')
  })
})
