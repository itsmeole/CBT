import { describe, expect, it } from 'vitest'
import { renderLatex, renderMathInHtml } from './katex'

describe('renderLatex', () => {
  it('merender formula inline ke html katex', () => {
    const html = renderLatex('E=mc^2', false)
    expect(html).toContain('katex')
  })

  it('merender formula display mode ke html katex', () => {
    const html = renderLatex('\\frac{a}{b}', true)
    expect(html).toContain('katex-display')
  })
})

describe('renderMathInHtml', () => {
  it('mengganti $...$ dengan KaTeX inline', () => {
    const text = 'Hitunglah nilai dari $x + y = 10$ sekarang.'
    const result = renderMathInHtml(text)
    expect(result).toContain('katex')
    expect(result).not.toContain('$x + y = 10$')
  })

  it('mengganti $$...$$ dengan KaTeX display', () => {
    const text = 'Rumus Pythagoras: $$\\sqrt{a^2 + b^2}$$'
    const result = renderMathInHtml(text)
    expect(result).toContain('katex-display')
    expect(result).not.toContain('$$\\sqrt{a^2 + b^2}$$')
  })

  it('mengembalikan teks kosong jika input kosong', () => {
    expect(renderMathInHtml('')).toBe('')
  })
})

