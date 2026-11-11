import type { Role } from '@/types/auth'

export type IkonMenu = 'dashboard' | 'users' | 'book' | 'calendar' | 'control'
export type IkonAnak = 'siswa' | 'guru' | 'pengampu' | 'kelas' | 'mapel' | 'ruang' | 'tahun' | 'jadwal' | 'paket' | 'bobot'

export interface MenuAnak {
  nama: string
  ikon: IkonAnak
  /** Nilai query `tab` yang dibaca halaman tujuan. */
  tab: string
}

export interface MenuItem {
  nama: string
  /** Path halaman (tanpa query). */
  to: string
  ikon: IkonMenu
  visible: boolean
  /** Sub-menu (dropdown). Tiap anak membuka halaman yang sama dengan query `tab` berbeda. */
  anak?: MenuAnak[]
}

/** Path SVG (gaya outline 24x24) untuk ikon menu utama dan sub-menu. */
export const PATH_IKON: Record<IkonMenu | IkonAnak, string> = {
  dashboard:
    'm2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25',
  users:
    'M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z',
  book: 'M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25',
  calendar:
    'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5',
  control:
    'M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75',
  siswa:
    'M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.627 48.627 0 0 1 12 20.904a48.627 48.627 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.57 50.57 0 0 0-2.658-.813A59.906 59.906 0 0 1 12 3.493a59.903 59.903 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.697 50.697 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342M6.75 15a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5Zm0 0v-3.675A55.378 55.378 0 0 1 12 8.443m-7.007 11.55A5.981 5.981 0 0 0 6.75 15.75v-1.5',
  guru: 'M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z',
  pengampu:
    'M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244',
  kelas:
    'M18 18.72a9.094 9.094 0 0 0 3.741-.479 3 3 0 0 0-4.682-2.72m.94 3.198.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0 1 12 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 0 1 6 18.719m12 0a5.971 5.971 0 0 0-.941-3.197m0 0A5.995 5.995 0 0 0 12 12.75a5.995 5.995 0 0 0-5.058 2.772m0 0a3 3 0 0 0-4.681 2.72 8.986 8.986 0 0 0 3.74.477m.94-3.197a5.971 5.971 0 0 0-.94 3.197M15 6.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z',
  mapel:
    'M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25',
  ruang:
    'M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Z',
  tahun:
    'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5m-9-6h.008v.008H12v-.008ZM12 15h.008v.008H12V15Zm0 2.25h.008v.008H12v-.008ZM9.75 15h.008v.008H9.75V15Zm0 2.25h.008v.008H9.75v-.008ZM7.5 15h.008v.008H7.5V15Zm0 2.25h.008v.008H7.5v-.008Zm6.75-4.5h.008v.008h-.008v-.008Zm0 2.25h.008v.008h-.008V15Zm0 2.25h.008v.008h-.008v-.008Zm2.25-4.5h.008v.008H16.5v-.008Zm0 2.25h.008v.008H16.5V15Z',
  jadwal:
    'M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5',
  paket:
    'm20.25 7.5-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z',
  bobot:
    'M12 3v17.25m0 0c-1.472 0-2.882.265-4.185.75M12 20.25c1.472 0 2.882.265 4.185.75M18.75 4.97A48.416 48.416 0 0 0 12 4.5c-2.291 0-4.545.16-6.75.47m13.5 0c1.01.143 2.01.317 3 .52m-3-.52 2.62 10.726c.122.499-.106 1.028-.589 1.202a5.988 5.988 0 0 1-2.031.352 5.988 5.988 0 0 1-2.031-.352c-.483-.174-.711-.703-.59-1.202L18.75 4.971Zm-16.5.52c.99-.203 1.99-.377 3-.52m0 0 2.62 10.726c.122.499-.106 1.028-.589 1.202a5.989 5.989 0 0 1-2.031.352 5.989 5.989 0 0 1-2.031-.352c-.483-.174-.711-.703-.59-1.202L5.25 4.971Z'
}

export const TAB_MASTER: MenuAnak[] = [
  { nama: 'Siswa', ikon: 'siswa', tab: 'siswa' },
  { nama: 'Guru', ikon: 'guru', tab: 'guru' },
  { nama: 'Pengampu Mapel', ikon: 'pengampu', tab: 'guru_mapel' },
  { nama: 'Kelas', ikon: 'kelas', tab: 'kelas' },
  { nama: 'Mata Pelajaran', ikon: 'mapel', tab: 'mapel' },
  { nama: 'Ruang Ujian', ikon: 'ruang', tab: 'ruang' },
  { nama: 'Tahun Ajaran', ikon: 'tahun', tab: 'tahun_ajaran' }
]

export const TAB_JADWAL: MenuAnak[] = [
  { nama: 'Jadwal & Sesi Ruang', ikon: 'jadwal', tab: 'jadwal' },
  { nama: 'Paket Soal', ikon: 'paket', tab: 'paket' },
  { nama: 'Bobot Soal', ikon: 'bobot', tab: 'bobot' }
]

export function buatMenu(role: Role | null): MenuItem[] {
  const operatorAtauSuperadmin = role === 'operator' || role === 'superadmin'
  const guruAtauSuperadmin = role === 'guru' || role === 'superadmin'

  return [
    { nama: 'Ringkasan', to: '/staf', ikon: 'dashboard', visible: true },
    { nama: 'Master Data', to: '/staf/master', ikon: 'users', visible: operatorAtauSuperadmin, anak: TAB_MASTER },
    { nama: 'Bank Soal', to: '/staf/bank-soal', ikon: 'book', visible: true },
    {
      nama: 'Jadwal & Paket Ujian',
      to: '/staf/jadwal',
      ikon: 'calendar',
      visible: operatorAtauSuperadmin || guruAtauSuperadmin,
      anak: TAB_JADWAL
    },
    { nama: 'Kontrol Sesi', to: '/staf/kontrol-sesi', ikon: 'control', visible: operatorAtauSuperadmin },
    { nama: 'Monitoring Pengawas', to: '/staf/monitoring', ikon: 'control', visible: true },
    { nama: 'Koreksi Essay', to: '/staf/koreksi-essay', ikon: 'book', visible: true },
    { nama: 'Rekap Nilai', to: '/staf/nilai', ikon: 'dashboard', visible: true },
    { nama: 'Analisis Butir', to: '/staf/analisis-butir', ikon: 'book', visible: guruAtauSuperadmin },
    { nama: 'Cetak Dokumen', to: '/staf/cetak', ikon: 'dashboard', visible: true },
    { nama: 'Riwayat Aktivitas', to: '/staf/audit-log', ikon: 'users', visible: role === 'superadmin' }
  ].filter((item) => item.visible) as MenuItem[]
}

/** Item aktif bila path saat ini berada di bawah path item (kecuali beranda yang harus persis). */
export function itemAktif(item: MenuItem, path: string): boolean {
  return item.to === '/staf' ? path === '/staf' : path === item.to || path.startsWith(item.to + '/')
}

export function tabDefault(item: MenuItem): string {
  return item.anak?.[0]?.tab ?? ''
}

/** Sub-menu aktif: halaman cocok dan query `tab` sama (tanpa query dianggap tab pertama). */
export function anakAktif(item: MenuItem, anak: MenuAnak, path: string, tabQuery: unknown): boolean {
  if (!itemAktif(item, path)) return false
  const tab = typeof tabQuery === 'string' && item.anak?.some((a) => a.tab === tabQuery) ? tabQuery : tabDefault(item)
  return tab === anak.tab
}

/** Normalisasi query `tab` dari URL ke tab yang valid; tab tak dikenal kembali ke default. */
export function tabValid(daftar: readonly MenuAnak[], tabQuery: unknown): string {
  return typeof tabQuery === 'string' && daftar.some((a) => a.tab === tabQuery) ? tabQuery : (daftar[0]?.tab ?? '')
}
