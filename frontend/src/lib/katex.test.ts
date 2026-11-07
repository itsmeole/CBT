import { describe, expect, it } from 'vitest'
import { decodeEntitasMath, renderLatex, renderMathInHtml } from './katex'

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


describe('decodeEntitasMath', () => {
  it('mengembalikan entitas HTML di dalam rumus sebelum dirender', () => {
    expect(decodeEntitasMath('a &lt; b &amp; c &gt; d')).toBe('a < b & c > d')
  })

  it('merender matriks yang pemisah kolomnya tersimpan sebagai &amp;', () => {
    const html = renderMathInHtml(String.raw`$$\begin{matrix} 1 &amp; 2 \\ 3 &amp; 4 \end{matrix}$$`)
    expect(html).toContain('katex-display')
    expect(html).not.toContain('&amp;amp;')
    expect(html).not.toContain('katex-error')
  })
})
