import katex from 'katex'

const cache = new Map<string, string>()

/**
 * Render LaTeX ke HTML KaTeX. Hasil di-cache di memori per ekspresi (NFR-FE-04).
 * Ekspresi yang tidak valid ditampilkan apa adanya sebagai teks (tidak melempar error ke UI).
 */
export function renderLatex(latex: string, displayMode = false): string {
  const key = `${displayMode ? 'd' : 'i'}:${latex}`
  const cached = cache.get(key)
  if (cached !== undefined) return cached

  const html = katex.renderToString(latex, {
    displayMode,
    throwOnError: false,
    output: 'htmlAndMathml',
    // Cegah perintah berbahaya seperti \href ke URL luar.
    trust: false
  })
  cache.set(key, html)
  return html
}

/**
 * Render semua rumus math inline ($...$) dan display ($$...$$) di dalam string HTML/teks.
 */
export function renderMathInHtml(content: string): string {
  if (!content) return ''
  // 1. Display math: $$...$$
  let result = content.replace(/\$\$([\s\S]+?)\$\$/g, (_, math) => {
    return renderLatex(math.trim(), true)
  })
  // 2. Inline math: $...$
  result = result.replace(/(^|[^\\])\$([^\$]+?)\$/g, (_, prefix, math) => {
    return `${prefix}${renderLatex(math.trim(), false)}`
  })
  return result
}


