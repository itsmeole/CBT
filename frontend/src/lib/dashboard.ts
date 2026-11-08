/** Bentuk respons `GET /dashboard` dan helper tampilan dashboard staf. */

export interface PesertaRingkas {
  total: number
  hadir: number
  menunggu: number
  mengerjakan: number
  terkunci: number
  selesai: number
}

export type StatusSesi = 'belum_mulai' | 'berjalan' | 'selesai'

export interface SesiHariIni {
  id: number
  jadwal: string | null
  mapel: string | null
  ruang: string | null
  sesi_ke: number
  jam_mulai: string
  jam_akhir: string
  token: string | null
  pengawas: string[]
  peserta: PesertaRingkas
  status: StatusSesi
}

export interface MasalahKesiapan {
  kode: string
  pesan: string
  jumlah: number
}

export interface KesiapanJadwal {
  jadwal_id: number
  nama: string
  mapel: string | null
  tanggal: string
  jumlah_sesi: number
  masalah: MasalahKesiapan[]
  siap: boolean
}

export interface TindakanPerluDiurus {
  kode: string
  pesan: string
  jumlah: number
  tautan: string
}

export interface PanelOperator {
  hari_ini: SesiHariIni[]
  langsung: {
    sesi_hari_ini: number
    sesi_berjalan: number
    peserta: number
    menunggu: number
    mengerjakan: number
    terkunci: number
    selesai: number
  }
  kesiapan: KesiapanJadwal[]
  perlu_tindakan: TindakanPerluDiurus[]
}

export interface PanelGuru {
  koreksi: {
    total: number
    /** Jumlah semua jadwal yang punya antrean; `per_jadwal` hanya memuat yang terbaru (maks. 5). */
    jumlah_jadwal: number
    per_jadwal: { jadwal_id: number; nama: string; mapel: string; tanggal: string; menunggu: number }[]
  }
  pengawasan: {
    sesi: {
      id: number
      jadwal: string | null
      mapel: string | null
      ruang: string | null
      tanggal: string
      sesi_ke: number
      jam_mulai: string
      jam_akhir: string
      hari_ini: boolean
      berita_acara: boolean
    }[]
    berita_acara_tertunggak: number
  }
  jadwal_saya: { jadwal_id: number; nama: string; mapel: string | null; tanggal: string; paket: string | null; jumlah_butir: number }[]
  hasil_terbaru: { jadwal_id: number; nama: string; mapel: string; tanggal: string; final: number; menunggu: number; rata_rata: number | null }[]
}

export interface PanelSistem {
  kesehatan: { antrean_menunggu: number | null; job_gagal: number; pdf_gagal_7_hari: number; backup_terakhir: string | null }
  aktivitas: { id: number; aksi: string; aktor: string | null; peran: string | null; waktu: string | null }[]
  kualitas_data: {
    tahun_ajaran_aktif: { nama: string; semester: number } | null
    guru_tanpa_pengampu: number
    mapel_tanpa_pengampu: number
    kelas_tanpa_siswa: number
    akun_nonaktif: number
  }
  pengguna: { superadmin: number; operator: number; guru: number; siswa: number }
}

export interface DataDashboard {
  peran: 'superadmin' | 'operator' | 'guru'
  tanggal: string
  zona_waktu: string
  operator?: PanelOperator
  guru?: PanelGuru
  sistem?: PanelSistem
}

export type Nada = 'baik' | 'peringatan' | 'bahaya' | 'netral' | 'info'

export const KELAS_NADA: Record<Nada, string> = {
  baik: 'bg-emerald-100 text-emerald-800',
  peringatan: 'bg-amber-100 text-amber-800',
  bahaya: 'bg-red-100 text-red-800',
  netral: 'bg-slate-100 text-slate-700',
  info: 'bg-sky-100 text-sky-800'
}

export const LABEL_STATUS_SESI: Record<StatusSesi, { teks: string; nada: Nada }> = {
  belum_mulai: { teks: 'Belum mulai', nada: 'netral' },
  berjalan: { teks: 'Berjalan', nada: 'info' },
  selesai: { teks: 'Selesai', nada: 'baik' }
}

export function sapaan(jam: number): string {
  if (jam < 11) return 'Selamat pagi'
  if (jam < 15) return 'Selamat siang'
  if (jam < 19) return 'Selamat sore'
  return 'Selamat malam'
}

/** Selisih hari kalender antara dua tanggal `YYYY-MM-DD` (b - a), tanpa pengaruh zona waktu perangkat. */
export function selisihHari(a: string, b: string): number {
  const ms = (t: string) => {
    const [y, m, d] = t.split('-').map(Number)
    return Date.UTC(y, m - 1, d)
  }
  return Math.round((ms(b) - ms(a)) / 86_400_000)
}

/** "Hari ini", "Besok", "Kemarin", atau tanggal pendek (mis. "Sen, 10 Nov"). `hariIni` berasal dari server. */
export function labelHari(tanggal: string, hariIni: string): string {
  const selisih = selisihHari(hariIni, tanggal)
  if (selisih === 0) return 'Hari ini'
  if (selisih === 1) return 'Besok'
  if (selisih === -1) return 'Kemarin'
  const [y, m, d] = tanggal.split('-').map(Number)
  return new Date(Date.UTC(y, m - 1, d)).toLocaleDateString('id-ID', { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' })
}

export function waktuRelatif(iso: string | null, sekarang: Date = new Date()): string {
  if (!iso) return '-'
  const detik = Math.max(0, Math.floor((sekarang.getTime() - new Date(iso).getTime()) / 1000))
  if (detik < 60) return 'baru saja'
  if (detik < 3600) return `${Math.floor(detik / 60)} menit lalu`
  if (detik < 86_400) return `${Math.floor(detik / 3600)} jam lalu`
  return `${Math.floor(detik / 86_400)} hari lalu`
}

/** Backup harian dianggap sehat bila terakhir dibuat kurang dari 36 jam lalu. */
export function statusBackup(iso: string | null, sekarang: Date = new Date()): { teks: string; nada: Nada } {
  if (!iso) return { teks: 'Belum ada backup', nada: 'bahaya' }
  const jam = (sekarang.getTime() - new Date(iso).getTime()) / 3_600_000
  return { teks: waktuRelatif(iso, sekarang), nada: jam < 36 ? 'baik' : 'peringatan' }
}

const LABEL_AKSI: Record<string, string> = {
  'auth.login': 'Login',
  'auth.logout': 'Logout',
  'auth.reset_login': 'Reset login siswa',
  'ujian.mulai_sesi': 'Memulai sesi ujian',
  'ujian.mulai_peserta': 'Memulai ujian siswa',
  'ujian.lock': 'Siswa terkunci',
  'ujian.unlock': 'Membuka kunci siswa',
  'ujian.dikirim_otomatis': 'Ujian dikirim otomatis (waktu habis)',
  'sesi.kehadiran_diperbarui': 'Memperbarui kehadiran',
  'essay.dikoreksi': 'Mengoreksi essay',
  'berita_acara.disimpan': 'Menyimpan berita acara',
  'laporan_pdf.diminta': 'Meminta dokumen PDF',
  'lock_threshold.updated': 'Mengubah ambang kunci',
  'import.excel.diajukan': 'Mengimpor data Excel',
  'jadwal.penempatan_dibuat': 'Menempatkan peserta ke ruang',
  'jadwal.penempatan_direset': 'Mereset penempatan peserta',
  'master_data.export_excel': 'Mengekspor data master',
  'bank_soal.export_word': 'Mengekspor bank soal',
  'paket_soal.export_word': 'Mengekspor paket soal'
}

export function labelAksi(aksi: string): string {
  return LABEL_AKSI[aksi] ?? aksi
}

/** Aksi yang perlu perhatian lebih (sensitif) ditandai pada feed aktivitas. */
export function aksiSensitif(aksi: string): boolean {
  return ['auth.reset_login', 'ujian.unlock', 'ujian.mulai_sesi', 'jadwal.penempatan_direset', 'lock_threshold.updated'].includes(aksi)
}

export function ringkasKesiapan(daftar: KesiapanJadwal[]): { siap: number; bermasalah: number } {
  const siap = daftar.filter((j) => j.siap).length
  return { siap, bermasalah: daftar.length - siap }
}

/** Persentase untuk bar proporsi; aman dari pembagian nol dan dijepit 0..100. */
export function persen(bagian: number, total: number): number {
  if (total <= 0) return 0
  return Math.min(100, Math.max(0, Math.round((bagian / total) * 100)))
}
