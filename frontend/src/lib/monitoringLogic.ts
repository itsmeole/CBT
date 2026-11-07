import type { PesertaStatus } from '@/stores/ujian'

/** Ringkasan peserta untuk dashboard pengawas. Tidak memuat soal, jawaban, atau nilai (FR-FE-96). */
export interface PesertaMonitoring {
  id: number
  nama: string
  nis: string
  kelas: string
  status: PesertaStatus
  terjawab: number
  jumlahKunci: number
  sisaDetik: number
  /** True bila jumlah kunci mencapai/melebihi ambang (FR-FE-93). */
  melebihiAmbang: boolean
}

export interface PesertaMentah {
  id: number
  nama: string | null
  nis: string | null
  kelas: string | null
  status: PesertaStatus
  terjawab: number
  jumlah_kunci: number
  sisa_detik: number
}

export function petakanPeserta(mentah: PesertaMentah[], ambang: number | null): PesertaMonitoring[] {
  return mentah.map((p) => ({
    id: p.id,
    nama: p.nama ?? '-',
    nis: p.nis ?? '-',
    kelas: p.kelas ?? '-',
    status: p.status,
    terjawab: p.terjawab,
    jumlahKunci: p.jumlah_kunci,
    sisaDetik: p.sisa_detik,
    melebihiAmbang: ambang !== null && p.jumlah_kunci >= ambang
  }))
}

const URUTAN_STATUS: Record<PesertaStatus, number> = {
  terkunci: 0,
  mengerjakan: 1,
  menunggu: 2,
  belum_login: 3,
  selesai: 4
}

/** Siswa terkunci selalu di urutan teratas (auto-pin, FR-FE-91), sisanya per status lalu nama. */
export function urutkanPeserta(daftar: PesertaMonitoring[]): PesertaMonitoring[] {
  return [...daftar].sort(
    (a, b) => URUTAN_STATUS[a.status] - URUTAN_STATUS[b.status] || a.nama.localeCompare(b.nama, 'id')
  )
}

export type EventSesi =
  | { jenis: 'status'; pesertaId: number; status: PesertaStatus }
  | { jenis: 'progress'; pesertaId: number; terjawab: number; sisaDetik: number }
  | { jenis: 'kunci'; pesertaId: number; jumlahKunci: number }
  | { jenis: 'ambang'; pesertaId: number; jumlahKunci: number }

export interface HasilEvent {
  daftar: PesertaMonitoring[]
  /** True bila ada peserta baru terkunci/melewati ambang sehingga perlu alert visual & audio (FR-FE-91). */
  perluAlert: boolean
}

export function terapkanEvent(daftar: PesertaMonitoring[], event: EventSesi): HasilEvent {
  let perluAlert = false
  const baru = daftar.map((p) => {
    if (p.id !== event.pesertaId) return p
    switch (event.jenis) {
      case 'status':
        if (event.status === 'terkunci' && p.status !== 'terkunci') perluAlert = true
        return { ...p, status: event.status }
      case 'progress':
        return { ...p, terjawab: event.terjawab, sisaDetik: event.sisaDetik }
      case 'kunci':
        perluAlert = true
        return { ...p, status: 'terkunci' as const, jumlahKunci: event.jumlahKunci }
      case 'ambang':
        perluAlert = true
        return { ...p, jumlahKunci: event.jumlahKunci, melebihiAmbang: true }
    }
  })
  return { daftar: baru, perluAlert }
}

export const ALASAN_CEPAT = [
  'Layar HP mati',
  'Panggilan masuk',
  'Notifikasi tidak sengaja tertekan',
  'Koneksi terputus',
  'Siswa tidak sengaja keluar halaman'
] as const

export function alasanValid(alasan: string): boolean {
  return alasan.trim().length >= 5
}

export const LABEL_STATUS: Record<PesertaStatus, string> = {
  belum_login: 'Belum login',
  menunggu: 'Menunggu',
  mengerjakan: 'Mengerjakan',
  terkunci: 'Terkunci',
  selesai: 'Selesai'
}

export function ringkasanStatus(daftar: PesertaMonitoring[]): Record<PesertaStatus, number> {
  const hasil: Record<PesertaStatus, number> = {
    belum_login: 0,
    menunggu: 0,
    mengerjakan: 0,
    terkunci: 0,
    selesai: 0
  }
  for (const p of daftar) hasil[p.status]++
  return hasil
}
