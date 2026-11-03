import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { hitungOffset, sisaDetik } from '@/lib/timer'

/** Status peserta, sama dengan `peserta_ujian.status` di backend. */
export type PesertaStatus = 'belum_login' | 'menunggu' | 'mengerjakan' | 'terkunci' | 'selesai'

/**
 * State sesi ujian siswa. Semua nilai berasal dari server; store ini tidak pernah
 * menentukan sendiri status, waktu selesai, atau nilai.
 */
export const useUjianStore = defineStore('ujian', () => {
  const status = ref<PesertaStatus>('belum_login')
  const waktuSelesaiMs = ref<number | null>(null)
  const offsetMs = ref(0)
  const nowMs = ref(Date.now())

  const terkunci = computed(() => status.value === 'terkunci')

  const sisaWaktuDetik = computed(() =>
    waktuSelesaiMs.value === null ? 0 : sisaDetik(waktuSelesaiMs.value, nowMs.value, offsetMs.value)
  )

  function sinkronWaktu(waktuServerMs: number): void {
    offsetMs.value = hitungOffset(waktuServerMs, Date.now())
  }

  function tick(): void {
    nowMs.value = Date.now()
  }

  function terapkanStateServer(payload: { status: PesertaStatus; waktuSelesaiMs: number | null }): void {
    status.value = payload.status
    waktuSelesaiMs.value = payload.waktuSelesaiMs
  }

  function reset(): void {
    status.value = 'belum_login'
    waktuSelesaiMs.value = null
  }

  return { status, waktuSelesaiMs, terkunci, sisaWaktuDetik, sinkronWaktu, tick, terapkanStateServer, reset }
})

