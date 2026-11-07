import { defineStore } from 'pinia'
import { ref } from 'vue'
import { terapkanEvent, urutkanPeserta, type EventSesi, type PesertaMonitoring } from '@/lib/monitoringLogic'

export type { PesertaMonitoring } from '@/lib/monitoringLogic'

export type KoneksiRealtime = 'terhubung' | 'terputus' | 'menyambung'

export const useMonitoringStore = defineStore('monitoring', () => {
  const peserta = ref<PesertaMonitoring[]>([])
  const koneksi = ref<KoneksiRealtime>('menyambung')
  const alertAktif = ref(false)

  function setPeserta(daftar: PesertaMonitoring[]): void {
    peserta.value = urutkanPeserta(daftar)
  }

  function setKoneksi(status: KoneksiRealtime): void {
    koneksi.value = status
  }

  /** Terapkan event realtime; mengembalikan true bila perlu alert visual/audio. */
  function terapkan(event: EventSesi): boolean {
    const hasil = terapkanEvent(peserta.value, event)
    peserta.value = urutkanPeserta(hasil.daftar)
    if (hasil.perluAlert) alertAktif.value = true
    return hasil.perluAlert
  }

  function tutupAlert(): void {
    alertAktif.value = false
  }

  return { peserta, koneksi, alertAktif, setPeserta, setKoneksi, terapkan, tutupAlert }
})
