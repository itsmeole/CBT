export type Role = 'superadmin' | 'operator' | 'guru' | 'siswa'

export interface AuthUser {
  id: number
  username: string
  nama: string
  role: Role
}

/** Format error seragam dari backend (lihat BE.md bagian 7). */
export interface ApiErrorBody {
  error: {
    code: string
    message: string
    details?: Record<string, string[]>
  }
}

