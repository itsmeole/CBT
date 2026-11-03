import type { Role } from '@/types/auth'

export interface RouteAccessMeta {
  /** Halaman tanpa login (mis. /login). */
  public?: boolean
  /** Daftar role yang boleh masuk. Kosong berarti semua role yang sudah login. */
  roles?: Role[]
}

export type GuardResult = true | { name: string }

/** Halaman beranda tiap role setelah login. */
export function berandaUntukRole(role: Role): string {
  return role === 'siswa' ? 'siswa-beranda' : 'staf-beranda'
}

/**
 * Keputusan akses route yang murni (mudah diuji).
 * Catatan: ini hanya untuk UX. Otorisasi sebenarnya selalu ditegakkan di server (Rules FE bagian 1).
 */
export function resolveGuard(meta: RouteAccessMeta, role: Role | null, routeName?: string | symbol | null): GuardResult {
  if (meta.public) {
    // Pengguna yang sudah login tidak perlu melihat halaman login lagi.
    if (role && routeName === 'login') return { name: berandaUntukRole(role) }
    return true
  }

  if (!role) return { name: 'login' }

  if (meta.roles && meta.roles.length > 0 && !meta.roles.includes(role)) {
    return { name: 'forbidden' }
  }

  return true
}

