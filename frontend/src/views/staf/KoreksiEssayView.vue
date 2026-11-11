<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import StafLayout from '@/components/StafLayout.vue'
import { apiClient } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import FilterJadwal from '@/components/FilterJadwal.vue'
import { renderMathInHtml } from '@/lib/katex'
import { normalisasiSkor, skorEssayValid, teksPanduan } from '@/lib/koreksi'

interface ItemKoreksi {
  jawaban_id: number | null
  peserta_id: number
  soal_id: number
  nama_siswa: string
  nis: string
  kelas: string
  konten_soal: string
  kunci: unknown
  rubrik: unknown
  jawaban: unknown
  skor_maksimal: string
  skor?: string
  catatan: string | null
  sudah_dikoreksi?: boolean
}

const auth = useAuthStore()
// Operator hanya melihat jawaban. Operator dan Superadmin melihat semua jawaban, termasuk yang sudah dikoreksi.
const bolehMenilai = computed(() => auth.role !== 'operator')
const lihatSemua = computed(() => auth.role === 'operator' || auth.role === 'superadmin')

const jadwalId = ref<number | null>(null)
const kelasId = ref<number | ''>('')
const antrean = ref<ItemKoreksi[]>([])
const indeks = ref(0)
const skorInput = ref('')
const catatan = ref('')
const loading = ref(false)
const menyimpan = ref(false)
const errorMessage = ref('')
const feedback = ref('')
const selesaiDikoreksi = ref(0)

// Hanya jawaban yang benar-benar ada yang bisa dinilai; jawaban kosong dihitung 0 otomatis oleh server.
const dapatDinilai = computed(() => antrean.value.filter((i) => i.jawaban_id !== null))
const menunggu = computed(() => dapatDinilai.value.filter((i) => !i.sudah_dikoreksi).length)
const kosong = computed(() => antrean.value.length - dapatDinilai.value.length)
const aktif = computed<ItemKoreksi | undefined>(() => dapatDinilai.value[indeks.value])
const bisaMenilaiAktif = computed(() => bolehMenilai.value && !!aktif.value && !aktif.value.sudah_dikoreksi)
const skorOk = computed(() => (aktif.value ? skorEssayValid(skorInput.value, aktif.value.skor_maksimal) : false))

async function muatAntrean() {
  if (!jadwalId.value) return
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await apiClient.get('/koreksi/essay', {
      params: {
        jadwal_id: jadwalId.value,
        ...(kelasId.value !== '' ? { kelas_id: kelasId.value } : {}),
        ...(lihatSemua.value ? { termasuk_dikoreksi: 1 } : {})
      }
    })
    antrean.value = res.data.data ?? []
    indeks.value = 0
    siapkanForm()
  } catch (err) {
    antrean.value = []
    errorMessage.value = err instanceof Error ? err.message : 'Gagal memuat antrean koreksi.'
  } finally {
    loading.value = false
  }
}

function siapkanForm() {
  skorInput.value = ''
  catatan.value = ''
}

async function simpan() {
  const item = aktif.value
  if (!item || !skorOk.value || menyimpan.value) return
  menyimpan.value = true
  errorMessage.value = ''
  try {
    await apiClient.put(`/koreksi/essay/${item.jawaban_id}`, {
      skor: normalisasiSkor(skorInput.value),
      catatan: catatan.value.trim() || null
    })
    selesaiDikoreksi.value++
    feedback.value = `Skor ${item.nama_siswa} tersimpan.`
    if (lihatSemua.value) {
      // Superadmin tetap melihat jawaban yang baru dikoreksi (ditandai sudah dikoreksi).
      item.sudah_dikoreksi = true
      item.skor = normalisasiSkor(skorInput.value)
      item.catatan = catatan.value.trim() || null
      if (indeks.value < dapatDinilai.value.length - 1) indeks.value++
    } else {
      // Guru: item yang sudah dikoreksi keluar dari antrean.
      antrean.value = antrean.value.filter((i) => i.jawaban_id !== item.jawaban_id)
      if (indeks.value >= dapatDinilai.value.length) indeks.value = Math.max(0, dapatDinilai.value.length - 1)
    }
    siapkanForm()
  } catch (err) {
    errorMessage.value = err instanceof Error ? err.message : 'Gagal menyimpan skor.'
  } finally {
    menyimpan.value = false
  }
}

function pindah(delta: number) {
  const baru = indeks.value + delta
  if (baru >= 0 && baru < dapatDinilai.value.length) {
    indeks.value = baru
    siapkanForm()
  }
}

watch([jadwalId, kelasId], () => {
  selesaiDikoreksi.value = 0
  feedback.value = ''
  void muatAntrean()
})
</script>

<template>
  <StafLayout>
    <div class="space-y-4">
      <div>
        <h1 class="text-2xl font-bold tracking-tight text-slate-900">Koreksi Essay</h1>
        <p class="text-sm text-slate-500">
          {{ bolehMenilai ? 'Nilai jawaban essay siswa. Nilai akhir dihitung ulang otomatis setelah skor disimpan.' : 'Lihat jawaban essay siswa. Penilaian dilakukan oleh guru pengampu.' }}
        </p>
      </div>

      <div v-if="errorMessage" class="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700" role="alert">{{ errorMessage }}</div>
      <div v-if="feedback" class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800" role="status">{{ feedback }}</div>

      <FilterJadwal v-model:jadwal-id="jadwalId" v-model:kelas-id="kelasId" @error="errorMessage = $event">
        <template #ringkasan>
          <span class="rounded-full bg-amber-100 px-2.5 py-1 font-semibold text-amber-800">Antrean: <strong>{{ menunggu }}</strong> jawaban menunggu</span>
          <span v-if="lihatSemua" class="rounded-full bg-slate-100 px-2.5 py-1 font-semibold text-slate-700">Total jawaban: <strong>{{ dapatDinilai.length }}</strong></span>
          <span v-if="bolehMenilai" class="rounded-full bg-emerald-100 px-2.5 py-1 font-semibold text-emerald-800">Dikoreksi sesi ini: <strong>{{ selesaiDikoreksi }}</strong></span>
          <span v-if="kosong > 0" class="rounded-full bg-slate-100 px-2.5 py-1 font-semibold text-slate-700">{{ kosong }} jawaban kosong dinilai 0 otomatis</span>
        </template>
      </FilterJadwal>

      <p v-if="loading" class="py-6 text-center text-sm text-slate-500">Memuat antrean...</p>
      <div v-else-if="!aktif" class="card bg-white p-8 text-center text-sm text-slate-500">
        {{ lihatSemua ? 'Belum ada jawaban essay pada jadwal ini.' : 'Tidak ada jawaban essay yang perlu dikoreksi.' }}
      </div>

      <div v-else class="grid gap-4 lg:grid-cols-2">
        <section class="card space-y-3 bg-white p-4" aria-label="Soal dan panduan">
          <div class="flex items-center justify-between text-xs text-slate-500">
            <span>Jawaban {{ indeks + 1 }} dari {{ dapatDinilai.length }}</span>
            <span>Skor maksimal: <strong>{{ aktif.skor_maksimal }}</strong></span>
          </div>
          <div class="prose max-w-none text-sm text-slate-800" v-html="renderMathInHtml(aktif.konten_soal)"></div>
          <div class="rounded-lg bg-emerald-50 p-3 text-sm">
            <p class="text-xs font-bold text-emerald-800">Kunci panduan</p>
            <p class="whitespace-pre-wrap text-emerald-900">{{ teksPanduan(aktif.kunci) }}</p>
          </div>
          <div class="rounded-lg bg-sky-50 p-3 text-sm">
            <p class="text-xs font-bold text-sky-800">Rubrik</p>
            <p class="whitespace-pre-wrap text-sky-900">{{ teksPanduan(aktif.rubrik) }}</p>
          </div>
        </section>

        <section class="card space-y-3 bg-white p-4" aria-label="Jawaban siswa">
          <p class="text-sm font-bold text-slate-900">{{ aktif.nama_siswa }} <span class="font-normal text-slate-500">({{ aktif.nis }} - {{ aktif.kelas }})</span></p>
          <div class="min-h-24 whitespace-pre-wrap rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-800">{{ teksPanduan(aktif.jawaban) }}</div>

          <template v-if="bisaMenilaiAktif">
            <label class="block text-xs font-semibold text-slate-600">
              Skor (0 - {{ aktif.skor_maksimal }})
              <input v-model="skorInput" inputmode="decimal" class="input mt-1" placeholder="mis. 7,5" :aria-invalid="skorInput !== '' && !skorOk" @keyup.enter="simpan" />
            </label>
            <p v-if="skorInput !== '' && !skorOk" class="text-xs text-red-700">Skor harus angka 0 sampai {{ aktif.skor_maksimal }} (maks. 2 desimal).</p>
            <label class="block text-xs font-semibold text-slate-600">
              Catatan (opsional)
              <textarea v-model="catatan" rows="2" class="input mt-1"></textarea>
            </label>

            <div class="flex gap-2">
              <button class="btn-secondary !min-h-touch" :disabled="indeks === 0" @click="pindah(-1)">Sebelumnya</button>
              <button class="btn-secondary !min-h-touch" :disabled="indeks >= dapatDinilai.length - 1" @click="pindah(1)">Lewati</button>
              <button class="btn-primary flex-1" :disabled="!skorOk || menyimpan" @click="simpan">{{ menyimpan ? 'Menyimpan...' : 'Simpan Skor' }}</button>
            </div>
          </template>

          <!-- Mode lihat: operator, atau jawaban yang sudah dikoreksi. -->
          <template v-else>
            <div class="rounded-lg border border-slate-200 p-3 text-sm">
              <p v-if="aktif.sudah_dikoreksi" class="font-semibold text-emerald-800">Sudah dikoreksi: skor {{ aktif.skor }} dari {{ aktif.skor_maksimal }}</p>
              <p v-else class="font-semibold text-amber-800">Belum dikoreksi</p>
              <p v-if="aktif.catatan" class="mt-1 whitespace-pre-wrap text-slate-600">Catatan: {{ aktif.catatan }}</p>
            </div>
            <div class="flex gap-2">
              <button class="btn-secondary !min-h-touch flex-1" :disabled="indeks === 0" @click="pindah(-1)">Sebelumnya</button>
              <button class="btn-secondary !min-h-touch flex-1" :disabled="indeks >= dapatDinilai.length - 1" @click="pindah(1)">Berikutnya</button>
            </div>
          </template>
        </section>
      </div>
    </div>
  </StafLayout>
</template>
