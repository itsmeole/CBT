<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import StafLayout from '@/components/StafLayout.vue'
import DashboardGuru from '@/components/dashboard/DashboardGuru.vue'
import DashboardOperator from '@/components/dashboard/DashboardOperator.vue'
import DashboardSistem from '@/components/dashboard/DashboardSistem.vue'
import { apiClient } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import { sapaan, waktuRelatif, type DataDashboard } from '@/lib/dashboard'

/** Interval pembaruan otomatis (ms). Dijeda saat tab tidak terlihat. */
const INTERVAL_MS = 30_000

const auth = useAuthStore()
const data = ref<DataDashboard | null>(null)
const loading = ref(true)
const memperbarui = ref(false)
const errorMessage = ref('')
const diperbaruiPada = ref<Date | null>(null)
const sekarang = ref(new Date())

const nama = computed(() => auth.user?.nama || auth.user?.username || '')
const labelPeran = computed(() => ({ superadmin: 'Superadmin', operator: 'Operator', guru: 'Guru' })[data.value?.peran ?? (auth.role as 'guru')] ?? '')
const tanggalPanjang = computed(() => {
  if (!data.value) return ''
  const [y, m, d] = data.value.tanggal.split('-').map(Number)
  return new Date(Date.UTC(y, m - 1, d)).toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' })
})
const jamSapaan = computed(() => sapaan(Number(new Intl.DateTimeFormat('id-ID', { hour: 'numeric', hour12: false, timeZone: 'Asia/Jakarta' }).format(sekarang.value)) % 24))
const labelDiperbarui = computed(() => (diperbaruiPada.value ? waktuRelatif(diperbaruiPada.value.toISOString(), sekarang.value) : ''))

async function muat(senyap = false) {
  if (senyap) memperbarui.value = true
  else loading.value = true
  try {
    const res = await apiClient.get('/dashboard')
    data.value = res.data.data
    diperbaruiPada.value = new Date()
    errorMessage.value = ''
  } catch (err) {
    // Saat pembaruan otomatis gagal, data lama tetap ditampilkan dan hanya pesan kecil yang muncul.
    errorMessage.value = err instanceof Error ? err.message : 'Gagal memuat dashboard.'
  } finally {
    loading.value = false
    memperbarui.value = false
  }
}

let timer: ReturnType<typeof setInterval> | null = null
let detakJam: ReturnType<typeof setInterval> | null = null

function saatTabTerlihat() {
  if (document.visibilityState === 'visible') void muat(true)
}

onMounted(() => {
  void muat()
  timer = setInterval(() => {
    if (document.visibilityState === 'visible') void muat(true)
  }, INTERVAL_MS)
  detakJam = setInterval(() => (sekarang.value = new Date()), 15_000)
  document.addEventListener('visibilitychange', saatTabTerlihat)
})

onUnmounted(() => {
  if (timer) clearInterval(timer)
  if (detakJam) clearInterval(detakJam)
  document.removeEventListener('visibilitychange', saatTabTerlihat)
})
</script>

<template>
  <StafLayout>
    <div class="space-y-5">
      <!-- Banner identitas sistem (dipertahankan dari dashboard sebelumnya). -->
      <header
        class="relative flex flex-col gap-4 overflow-hidden rounded-3xl bg-brand-700 bg-cover bg-center bg-no-repeat p-6 text-white shadow-md sm:flex-row sm:items-end sm:justify-between sm:p-8"
        style="background-image: url('/banner.svg'); background-size: cover; background-position: center;"
      >
        <div class="relative z-10 max-w-3xl">
          <span class="mb-2 inline-block rounded-lg bg-white/20 px-2.5 py-1 text-xs font-bold text-white backdrop-blur-xs">
            Panel {{ labelPeran }}
          </span>
          <h1 class="text-2xl font-extrabold tracking-tight sm:text-3xl">{{ jamSapaan }}, {{ nama }}</h1>
          <p class="mt-2 max-w-3xl text-sm font-medium leading-relaxed text-blue-100 sm:text-base">
            Integrated Computer Based Testing (ICBT) • Evaluate Knowledge, Inspire Growth.
          </p>
          <p v-if="tanggalPanjang" class="mt-1 text-xs text-blue-100/90">{{ tanggalPanjang }}</p>
        </div>
        <div class="relative z-10 flex items-center gap-3 text-xs text-blue-100">
          <span v-if="labelDiperbarui" role="status">Diperbarui {{ labelDiperbarui }}</span>
          <button
            class="rounded-lg bg-white/20 px-3 py-1.5 text-xs font-bold text-white backdrop-blur-xs transition hover:bg-white/30 disabled:opacity-60"
            :disabled="memperbarui || loading"
            @click="muat(true)"
          >
            {{ memperbarui ? 'Memperbarui...' : 'Perbarui' }}
          </button>
        </div>
      </header>

      <div v-if="errorMessage" class="flex items-center justify-between gap-3 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700" role="alert">
        <span>{{ errorMessage }}<span v-if="data"> Menampilkan data terakhir yang berhasil dimuat.</span></span>
        <button class="shrink-0 font-bold underline" @click="muat()">Coba lagi</button>
      </div>

      <div v-if="loading && !data" class="grid grid-cols-2 gap-3 lg:grid-cols-4" aria-busy="true" aria-label="Memuat dashboard">
        <div v-for="n in 4" :key="n" class="h-24 animate-pulse rounded-2xl bg-slate-200/70"></div>
      </div>

      <template v-else-if="data">
        <!-- Superadmin: operasional ujian di atas (kerja harian), kesehatan sistem di bawahnya. -->
        <template v-if="data.operator">
          <h2 v-if="data.sistem" class="text-xs font-bold text-slate-500">Operasional ujian</h2>
          <DashboardOperator :data="data.operator" :hari-ini="data.tanggal" />
        </template>
        <template v-if="data.sistem">
          <h2 class="pt-2 text-xs font-bold text-slate-500">Sistem dan data</h2>
          <DashboardSistem :data="data.sistem" />
        </template>
        <DashboardGuru v-if="data.guru" :data="data.guru" :hari-ini="data.tanggal" />
      </template>
    </div>
  </StafLayout>
</template>
