<script setup lang="ts">
import { tanggalPendek } from '@/lib/waktu'
import { computed, onMounted, ref, watch } from 'vue'
import StafLayout from '@/components/StafLayout.vue'
import { apiClient } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { LABEL_LAPORAN, basisLaporan, cetakLaporan, jenisUntukRole, type JenisLaporan } from '@/lib/laporan'

interface Opsi {
  id: number
  label: string
}

const auth = useAuthStore()
const jenisTersedia = computed(() => jenisUntukRole(auth.role))
const jenis = ref<JenisLaporan>('daftar-hadir')
const jadwalOpsi = ref<Opsi[]>([])
const sesiOpsi = ref<Opsi[]>([])
const kelasOpsi = ref<Opsi[]>([])
const jadwalId = ref<number | null>(null)
const sesiId = ref<number | null>(null)
const kelasId = ref<number | ''>('')
const sedangProses = ref(false)
const percobaan = ref(0)
const errorMessage = ref('')
const feedback = ref('')

const basis = computed(() => basisLaporan(jenis.value))
const siap = computed(() => (basis.value === 'jadwal' ? jadwalId.value !== null : sesiId.value !== null))
const pakaiKelas = computed(() => jenis.value === 'kartu-peserta' || jenis.value === 'rekap-nilai')

async function muat() {
  try {
    const [jadwal, sesi, kelas] = await Promise.all([
      apiClient.get('/jadwal'),
      apiClient.get('/pengawasan/sesi'),
      apiClient.get('/kelas')
    ])
    jadwalOpsi.value = (jadwal.data.data ?? []).map((j: { id: number; nama: string; mapel?: { nama: string } }) => ({
      id: j.id,
      label: `${j.nama}${j.mapel ? ` - ${j.mapel.nama}` : ''}`
    }))
    sesiOpsi.value = (sesi.data.data ?? []).map(
      (s: { id: number; jadwal: string; mapel: string; ruang: string; tanggal: string | null; sesi_ke: number }) => ({
        id: s.id,
        label: `${tanggalPendek(s.tanggal)} ${s.jadwal} - ${s.mapel ?? ''} (${s.ruang ?? 'Ruang'}, sesi ${s.sesi_ke})`.trim()
      })
    )
    kelasOpsi.value = (kelas.data.data ?? []).map((k: { id: number; nama: string }) => ({ id: k.id, label: k.nama }))
    jadwalId.value = jadwalOpsi.value[0]?.id ?? null
    sesiId.value = sesiOpsi.value[0]?.id ?? null
    if (!jenisTersedia.value.includes(jenis.value)) jenis.value = jenisTersedia.value[0] ?? 'daftar-hadir'
  } catch (err) {
    errorMessage.value = err instanceof Error ? err.message : 'Gagal memuat pilihan dokumen.'
  }
}

async function cetak() {
  if (!siap.value || sedangProses.value) return
  sedangProses.value = true
  percobaan.value = 0
  errorMessage.value = ''
  feedback.value = ''
  try {
    await cetakLaporan(
      jenis.value,
      {
        jadwal_id: jadwalId.value ?? undefined,
        sesi_ruang_id: sesiId.value ?? undefined,
        kelas_id: pakaiKelas.value && kelasId.value !== '' ? kelasId.value : undefined
      },
      { onPercobaan: (ke) => (percobaan.value = ke) }
    )
    feedback.value = `${LABEL_LAPORAN[jenis.value]} berhasil dibuat dan diunduh.`
  } catch (err) {
    errorMessage.value = err instanceof Error ? err.message : 'Gagal membuat dokumen.'
  } finally {
    sedangProses.value = false
  }
}

watch(jenis, () => {
  feedback.value = ''
  errorMessage.value = ''
})
onMounted(muat)
</script>

<template>
  <StafLayout>
    <div class="mx-auto max-w-2xl space-y-4">
      <div>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900">Cetak Dokumen</h1>
        <p class="text-sm text-slate-500">Buat PDF kartu peserta, daftar hadir, berita acara, dan rekap nilai. Dokumen dibuat di latar belakang lalu diunduh otomatis.</p>
      </div>

      <div v-if="errorMessage" class="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700" role="alert">{{ errorMessage }}</div>
      <div v-if="feedback" class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800" role="status">{{ feedback }}</div>

      <div class="card space-y-3 bg-white p-4">
        <label class="block text-xs font-semibold text-slate-600">
          Jenis dokumen
          <select v-model="jenis" class="input mt-1">
            <option v-for="j in jenisTersedia" :key="j" :value="j">{{ LABEL_LAPORAN[j] }}</option>
          </select>
        </label>

        <label v-if="basis === 'jadwal'" class="block text-xs font-semibold text-slate-600">
          Jadwal ujian
          <select v-model="jadwalId" class="input mt-1">
            <option v-for="j in jadwalOpsi" :key="j.id" :value="j.id">{{ j.label }}</option>
          </select>
        </label>
        <label v-else class="block text-xs font-semibold text-slate-600">
          Sesi ruang
          <select v-model="sesiId" class="input mt-1">
            <option v-for="s in sesiOpsi" :key="s.id" :value="s.id">{{ s.label }}</option>
          </select>
        </label>
        <p v-if="basis === 'sesi' && sesiOpsi.length === 0" class="text-xs text-amber-700">Belum ada sesi yang bisa Anda cetak (pengawas hanya sesi yang ditugaskan).</p>

        <label v-if="pakaiKelas" class="block text-xs font-semibold text-slate-600">
          Kelas (opsional)
          <select v-model="kelasId" class="input mt-1">
            <option value="">Semua kelas</option>
            <option v-for="k in kelasOpsi" :key="k.id" :value="k.id">{{ k.label }}</option>
          </select>
        </label>

        <button class="btn-primary w-full" :disabled="!siap || sedangProses" @click="cetak">
          {{ sedangProses ? `Membuat PDF... (${percobaan})` : 'Buat & Unduh PDF' }}
        </button>
      </div>
    </div>
  </StafLayout>
</template>
