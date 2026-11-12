<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { apiClient } from '@/api/client'
import { labelJadwal, pilihJadwal, saringJadwal, type JadwalRingkas } from '@/lib/filterJadwal'

/**
 * Filter bertingkat Mapel -> Jadwal ujian, ditambah Kelas.
 * Daftar mapel dan kelas dari server sudah dibatasi sesuai hak akses: guru hanya melihat
 * mapel dan kelas yang diampunya.
 */
const jadwalId = defineModel<number | null>('jadwalId', { default: null })
const kelasId = defineModel<number | ''>('kelasId', { default: '' })

const emit = defineEmits<{ (e: 'error', pesan: string): void }>()

interface Opsi {
  id: number
  nama: string
  kode?: string
}

const mapelOpsi = ref<Opsi[]>([])
const kelasOpsi = ref<Opsi[]>([])
const jadwal = ref<JadwalRingkas[]>([])
const mapelId = ref<number | ''>('')

const jadwalTersaring = computed(() => saringJadwal(jadwal.value, mapelId.value))

async function muat() {
  try {
    const [m, k, j] = await Promise.all([apiClient.get('/mapel'), apiClient.get('/kelas'), apiClient.get('/jadwal')])
    mapelOpsi.value = m.data.data ?? []
    kelasOpsi.value = k.data.data ?? []
    jadwal.value = j.data.data ?? []
    jadwalId.value = pilihJadwal(jadwalTersaring.value, jadwalId.value)
  } catch (err) {
    emit('error', err instanceof Error ? err.message : 'Gagal memuat pilihan filter.')
  }
}

watch(mapelId, () => {
  jadwalId.value = pilihJadwal(jadwalTersaring.value, jadwalId.value)
})

onMounted(muat)
</script>

<template>
  <section class="card space-y-3 bg-white p-4" aria-label="Filter data">
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-[1fr_1.4fr_0.8fr_1fr]">
      <label class="min-w-0 text-xs font-semibold text-slate-600">
        Mata pelajaran
        <select v-model="mapelId" class="input mt-1 !min-h-0 !py-2 text-sm" aria-label="Filter mata pelajaran">
          <option value="">Semua mapel</option>
          <option v-for="m in mapelOpsi" :key="m.id" :value="m.id">{{ m.kode || m.nama }}</option>
        </select>
      </label>
      <label class="min-w-0 text-xs font-semibold text-slate-600">
        Jadwal ujian
        <select v-model="jadwalId" class="input mt-1 !min-h-0 !py-2 text-sm" aria-label="Pilih jadwal ujian" :disabled="jadwalTersaring.length === 0">
          <option v-if="jadwalTersaring.length === 0" :value="null">Tidak ada jadwal</option>
          <option v-for="j in jadwalTersaring" :key="j.id" :value="j.id">{{ labelJadwal(j) }}</option>
        </select>
      </label>
      <label class="min-w-0 text-xs font-semibold text-slate-600">
        Kelas
        <select v-model="kelasId" class="input mt-1 !min-h-0 !py-2 text-sm" aria-label="Filter kelas">
          <option value="">Semua kelas</option>
          <option v-for="k in kelasOpsi" :key="k.id" :value="k.id">{{ k.nama }}</option>
        </select>
      </label>
      <slot />
    </div>
    <div v-if="$slots.ringkasan" class="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3 text-xs text-slate-600">
      <slot name="ringkasan" />
    </div>
  </section>
</template>
