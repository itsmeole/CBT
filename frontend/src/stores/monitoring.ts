import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { PesertaStatus } from './ujian'

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
}

export type KoneksiRealtime = 'terhubung' | 'terputus' | 'menyambung'

export const useMonitoringStore = defineStore('monitoring', () => {
  const peserta = ref<PesertaMonitoring[]>([])
  const koneksi = ref<KoneksiRealtime>('menyambung')

  function setPeserta(daftar: PesertaMonitoring[]): void {
    peserta.value = daftar
  }

  function setKoneksi(status: KoneksiRealtime): void {
    koneksi.value = status
  }

  return { peserta, koneksi, setPeserta, setKoneksi }
})

