import { describe, expect, it } from 'vitest'
import { berandaUntukRole, resolveGuard } from './guard'

describe('resolveGuard', () => {
  it('mengizinkan halaman publik tanpa login', () => {
    expect(resolveGuard({ public: true }, null, 'forbidden')).toBe(true)
  })

  it('mengarahkan pengguna belum login ke halaman login', () => {
    expect(resolveGuard({ roles: ['siswa'] }, null)).toEqual({ name: 'login' })
  })

  it('mengarahkan pengguna yang sudah login dari /login ke berandanya', () => {
    expect(resolveGuard({ public: true }, 'siswa', 'login')).toEqual({ name: 'siswa-beranda' })
    expect(resolveGuard({ public: true }, 'guru', 'login')).toEqual({ name: 'staf-beranda' })
  })

  it('menolak role yang tidak diizinkan', () => {
    expect(resolveGuard({ roles: ['operator', 'superadmin'] }, 'guru')).toEqual({ name: 'forbidden' })
    expect(resolveGuard({ roles: ['siswa'] }, 'operator')).toEqual({ name: 'forbidden' })
  })

  it('mengizinkan role yang sesuai', () => {
    expect(resolveGuard({ roles: ['operator', 'superadmin'] }, 'operator')).toBe(true)
  })

  it('mengizinkan semua role login jika roles tidak ditentukan', () => {
    expect(resolveGuard({}, 'guru')).toBe(true)
  })
})

describe('berandaUntukRole', () => {
  it('siswa ke beranda siswa, role lain ke beranda staf', () => {
    expect(berandaUntukRole('siswa')).toBe('siswa-beranda')
    expect(berandaUntukRole('superadmin')).toBe('staf-beranda')
  })
})

