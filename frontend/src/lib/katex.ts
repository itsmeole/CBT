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
 * Konten soal disanitasi di BE sehingga `&`, `<`, `>` di dalam rumus tersimpan sebagai entitas HTML.
 * KaTeX membutuhkan karakter aslinya (mis. `&` pemisah kolom matriks).
 */
export function decodeEntitasMath(math: string): string {
  return math.replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&')
}

/**
 * Render semua rumus math inline ($...$) dan display ($$...$$) di dalam string HTML/teks,
 * serta memastikan path media relatif (/storage/...) mengarah ke backend host.
 */
export function renderMathInHtml(content: string): string {
  if (!content) return ''
  let result = content

  // Normalisasi path relatif gambar/audio /storage/ ke backend host jika diperlukan
  const apiBase = (import.meta.env.VITE_API_BASE_URL || '').replace(/\/api\/v1\/?$/, '')
  if (apiBase) {
    result = result.replace(/<img([^>]+)src=["']\/(storage\/[^"']+)["']/gi, `<img$1src="${apiBase}/$2"`)
    result = result.replace(/<audio([^>]+)src=["']\/(storage\/[^"']+)["']/gi, `<audio$1src="${apiBase}/$2"`)
  }

  // 1. Display math: $$...$$
  result = result.replace(/\$\$([\s\S]+?)\$\$/g, (_, math) => {
    return renderLatex(decodeEntitasMath(math).trim(), true)
  })
  // 2. Inline math: $...$
  result = result.replace(/(^|[^\\])\$([^\$]+?)\$/g, (_, prefix, math) => {
    return `${prefix}${renderLatex(decodeEntitasMath(math).trim(), false)}`
  })
  return result
}


