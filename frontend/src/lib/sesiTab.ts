/**
 * Penyimpanan token sesi per tab browser (sessionStorage), agar refresh halaman tidak mengeluarkan pengguna.
 *
 * Pengecualian yang disengaja dari aturan "token hanya di memori": hanya selama tab terbuka (hilang saat tab
 * ditutup), tidak pernah ke localStorage, dan dihapus saat logout atau saat server menyatakan sesi berakhir.
 */
const KUNCI = 'cbt_sesi'

export function simpanTokenTab(token: string): void {
  try {
    sessionStorage.setItem(KUNCI, token)
  } catch {
    /* penyimpanan tidak tersedia (mis. mode privat): sesi tetap berjalan di memori */
  }
}

export function bacaTokenTab(): string | null {
  try {
    return sessionStorage.getItem(KUNCI)
  } catch {
    return null
  }
}

export function hapusTokenTab(): void {
  try {
    sessionStorage.removeItem(KUNCI)
  } catch {
    /* abaikan */
  }
}
