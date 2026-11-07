<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import StafLayout from '@/components/StafLayout.vue'
import { apiClient } from '@/api/client'
import { kategoriDayaPembeda, kategoriKesukaran, persenBar } from '@/lib/analisis'

interface Butir {
  nomor: number
  soal_id: number
  tipe: string
  skor_maksimal: string
  jumlah_peserta_final: number
  indeks_kesukaran: number | null
  daya_pembeda: number | null
}
interface Analisis {
  jumlah_peserta_final: number
  metode: { indeks_kesukaran: string; daya_pembeda: string }
  butir: Butir[]
}
interface Opsi {
  id: number
  label: string
}

const jadwalOpsi = ref<Opsi[]>([])
const kelasOpsi = ref<Opsi[]>([])
const jadwalId = ref<number | null>(null)
const kelasId = ref<number | ''>('')
const hasil = ref<Analisis | null>(null)
const loading = ref(false)
const errorMessage = ref('')

async function muatPilihan() {
  try {
    const [jadwal, kelas] = await Promise.all([apiClient.get('/jadwal'), apiClient.get('/kelas')])
    jadwalOpsi.value = (jadwal.data.data ?? []).map((j: { id: number; nama: string; mapel?: { nama: string } }) => ({
      id: j.id,
      label: `${j.nama}${j.mapel ? ` - ${j.mapel.nama}` : ''}`
    }))
    kelasOpsi.value = (kelas.data.data ?? []).map((k: { id: number; nama: string }) => ({ id: k.id, label: k.nama }))
    jadwalId.value = jadwalOpsi.value[0]?.id ?? null
  } catch (err) {
    errorMessage.value = err instanceof Error ? err.message : 'Gagal memuat pilihan.'
  }
}

async function muatAnalisis() {
  if (!jadwalId.value) return
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await apiClient.get(`/analisis-butir/${jadwalId.value}`, {
      params: kelasId.value !== '' ? { kelas_id: kelasId.value } : {}
    })
    hasil.value = res.data.data
  } catch (err) {
    hasil.value = null
    errorMessage.value = err instanceof Error ? err.message : 'Gagal memuat analisis butir.'
  } finally {
    loading.value = false
  }
}

watch([jadwalId, kelasId], muatAnalisis)
onMounted(muatPilihan)
</script>

<template>
  <StafLayout>
    <div class="space-y-4">
      <div>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900">Analisis Butir Soal</h1>
        <p class="text-sm text-slate-500">Tingkat kesukaran dan daya pembeda tiap butir, dihitung dari peserta dengan nilai final.</p>
      </div>

      <div v-if="errorMessage" class="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700" role="alert">{{ errorMessage }}</div>

      <div class="card grid gap-3 bg-white p-4 sm:grid-cols-2">
        <label class="text-xs font-semibold text-slate-600">
          Jadwal ujian
          <select v-model="jadwalId" class="input mt-1">
            <option v-for="j in jadwalOpsi" :key="j.id" :value="j.id">{{ j.label }}</option>
          </select>
        </label>
        <label class="text-xs font-semibold text-slate-600">
          Kelas (opsional)
          <select v-model="kelasId" class="input mt-1">
            <option value="">Semua kelas yang diampu</option>
            <option v-for="k in kelasOpsi" :key="k.id" :value="k.id">{{ k.label }}</option>
          </select>
        </label>
      </div>

      <p v-if="loading" class="py-6 text-center text-sm text-slate-500">Menghitung analisis...</p>

      <template v-else-if="hasil">
        <p class="text-xs text-slate-500">
          Peserta berstatus final: <strong>{{ hasil.jumlah_peserta_final }}</strong>
          <span v-if="hasil.jumlah_peserta_final < 3"> - daya pembeda memerlukan minimal 3 peserta final.</span>
        </p>

        <div class="card overflow-x-auto bg-white">
          <table class="w-full min-w-[640px] text-left text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-xs uppercase text-slate-500">
              <tr>
                <th class="px-3 py-2">No</th>
                <th class="px-3 py-2">Tipe</th>
                <th class="px-3 py-2">Kesukaran (p)</th>
                <th class="px-3 py-2">Daya pembeda (D)</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="b in hasil.butir" :key="b.soal_id" class="border-b border-slate-100 align-top">
                <td class="px-3 py-2 font-bold text-slate-900">{{ b.nomor }}</td>
                <td class="px-3 py-2 text-xs uppercase text-slate-600">{{ b.tipe.replace('_', ' ') }}</td>
                <td class="px-3 py-2">
                  <div class="flex items-center gap-2">
                    <div class="h-2 w-24 overflow-hidden rounded-full bg-slate-200" role="img" :aria-label="`p ${b.indeks_kesukaran ?? 0}`">
                      <div class="h-full bg-brand-600" :style="{ width: persenBar(b.indeks_kesukaran) + '%' }"></div>
                    </div>
                    <span class="font-mono text-xs">{{ b.indeks_kesukaran ?? '-' }}</span>
                  </div>
                  <span class="mt-1 inline-block rounded-full px-2 py-0.5 text-xs font-semibold" :class="kategoriKesukaran(b.indeks_kesukaran).kelas">
                    {{ kategoriKesukaran(b.indeks_kesukaran).teks }}
                  </span>
                </td>
                <td class="px-3 py-2">
                  <span class="font-mono text-xs">{{ b.daya_pembeda ?? '-' }}</span>
                  <span class="ml-2 inline-block rounded-full px-2 py-0.5 text-xs font-semibold" :class="kategoriDayaPembeda(b.daya_pembeda).kelas">
                    {{ kategoriDayaPembeda(b.daya_pembeda).teks }}
                  </span>
                </td>
              </tr>
              <tr v-if="hasil.butir.length === 0">
                <td colspan="4" class="px-3 py-8 text-center text-slate-500">Belum ada butir untuk dianalisis.</td>
              </tr>
            </tbody>
          </table>
        </div>

        <details class="text-xs text-slate-500">
          <summary class="cursor-pointer font-semibold">Metode perhitungan</summary>
          <p class="mt-1">p: {{ hasil.metode.indeks_kesukaran }}.</p>
          <p>D: {{ hasil.metode.daya_pembeda }}.</p>
        </details>
      </template>
    </div>
  </StafLayout>
</template>
