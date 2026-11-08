<script setup lang="ts">
import TabelMemuat from '@/components/TabelMemuat.vue'
import { computed, ref, watch } from 'vue'
import StafLayout from '@/components/StafLayout.vue'
import { apiClient } from '@/api/client'
import FilterJadwal from '@/components/FilterJadwal.vue'
import { labelStatusNilai } from '@/lib/koreksi'

interface BarisNilai {
  peserta_id: number
  nama_siswa: string
  nis: string
  kelas: string
  status_peserta: string
  nilai_status: string | null
  total: string | null
  maksimal: string | null
  nilai_akhir: string | null
}

const jadwalId = ref<number | null>(null)
const kelasId = ref<number | ''>('')
const baris = ref<BarisNilai[]>([])
const keyword = ref('')
const loading = ref(false)
const errorMessage = ref('')

const tampil = computed(() => {
  const k = keyword.value.trim().toLowerCase()
  return k ? baris.value.filter((b) => b.nama_siswa?.toLowerCase().includes(k) || b.nis?.includes(k)) : baris.value
})
const menunggu = computed(() => baris.value.filter((b) => b.status_peserta === 'selesai' && b.nilai_status !== 'final').length)
const final = computed(() => baris.value.filter((b) => b.nilai_status === 'final').length)

async function muatNilai() {
  if (!jadwalId.value) return
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await apiClient.get('/nilai', {
      params: kelasId.value !== '' ? { jadwal_id: jadwalId.value, kelas_id: kelasId.value } : { jadwal_id: jadwalId.value }
    })
    baris.value = res.data.data ?? []
  } catch (err) {
    baris.value = []
    errorMessage.value = err instanceof Error ? err.message : 'Gagal memuat rekap nilai.'
  } finally {
    loading.value = false
  }
}

watch([jadwalId, kelasId], muatNilai)
</script>

<template>
  <StafLayout>
    <div class="space-y-4">
      <div>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900">Rekap Nilai</h1>
        <p class="text-sm text-slate-500">Nilai bersifat sementara sampai seluruh essay selesai dikoreksi.</p>
      </div>
      <div v-if="errorMessage" class="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700" role="alert">{{ errorMessage }}</div>

      <FilterJadwal v-model:jadwal-id="jadwalId" v-model:kelas-id="kelasId" @error="errorMessage = $event">
        <label class="min-w-0 text-xs font-semibold text-slate-600">
          Cari nama / NIS
          <input v-model="keyword" class="input mt-1 !min-h-0 !py-2 text-sm" placeholder="Ketik untuk mencari..." />
        </label>
        <template #ringkasan>
          <span class="rounded-full bg-emerald-100 px-2.5 py-1 font-semibold text-emerald-800">Final: <strong>{{ final }}</strong></span>
          <span class="rounded-full bg-amber-100 px-2.5 py-1 font-semibold text-amber-800">Menunggu koreksi essay: <strong>{{ menunggu }}</strong></span>
          <span class="rounded-full bg-slate-100 px-2.5 py-1 font-semibold text-slate-700">Total peserta: <strong>{{ baris.length }}</strong></span>
        </template>
      </FilterJadwal>

      <div class="card overflow-x-auto bg-white">
        <table class="w-full min-w-[640px] text-left text-sm">
          <thead class="border-b border-slate-200 bg-slate-50 text-xs text-slate-500">
            <tr>
              <th class="px-3 py-2">Nama</th>
              <th class="px-3 py-2">NIS</th>
              <th class="px-3 py-2">Kelas</th>
              <th class="px-3 py-2 text-right">Skor</th>
              <th class="px-3 py-2 text-right">Nilai</th>
              <th class="px-3 py-2">Status</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="b in tampil" :key="b.peserta_id" class="border-b border-slate-100">
              <td class="px-3 py-2 font-medium text-slate-900">{{ b.nama_siswa }}</td>
              <td class="px-3 py-2">{{ b.nis }}</td>
              <td class="px-3 py-2">{{ b.kelas }}</td>
              <td class="px-3 py-2 text-right">{{ b.total ?? '-' }} / {{ b.maksimal ?? '-' }}</td>
              <td class="px-3 py-2 text-right font-bold">{{ b.nilai_akhir ?? '-' }}</td>
              <td class="px-3 py-2">
                <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="labelStatusNilai(b.nilai_status, b.status_peserta).kelas">
                  {{ labelStatusNilai(b.nilai_status, b.status_peserta).teks }}
                </span>
              </td>
            </tr>
            <TabelMemuat v-if="loading && tampil.length === 0" :kolom="6" />
            <tr v-if="!loading && tampil.length === 0">
              <td colspan="6" class="px-3 py-8 text-center text-slate-500">Belum ada data nilai.</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </StafLayout>
</template>
