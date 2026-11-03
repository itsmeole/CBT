<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { apiClient } from '@/api/client'

const auth = useAuthStore()
const router = useRouter()

const token = ref('')
const loading = ref(false)
const errorMessage = ref('')

const checklist = ref({
  baterai: true,
  koneksi: true,
  fokus: true
})

function onTokenInput(e: Event) {
  const target = e.target as HTMLInputElement
  token.value = target.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 6)
}

async function joinUjian() {
  if (token.value.length < 6) {
    errorMessage.value = 'Token ujian harus terdiri dari 6 karakter.'
    return
  }
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await apiClient.post('/ujian/join', { token: token.value })
    if (res.data.data?.status === 'mengerjakan') {
      router.push({ name: 'siswa-kerjakan' })
    } else {
      router.push({ name: 'siswa-tunggu' })
    }
  } catch (err: any) {
    errorMessage.value = err.message || 'Token tidak valid atau sesi belum dibuka.'
  } finally {
    loading.value = false
  }
}

async function logout() {
  await auth.logout()
  router.replace({ name: 'login' })
}
</script>

<template>
  <main class="mx-auto flex min-h-dvh max-w-md flex-col justify-between p-4 sm:p-6">
    <!-- Header -->
    <header class="flex items-center justify-between border-b border-slate-200 pb-3">
      <div>
        <h1 class="text-lg font-bold text-slate-800">CBT Siswa</h1>
        <p class="text-xs text-slate-500">{{ auth.user?.nama }} ({{ auth.user?.username || 'Peserta' }})</p>
      </div>
      <button @click="logout" class="btn-secondary !min-h-touch !min-w-touch !px-3 !py-1 text-xs">
        Keluar
      </button>
    </header>

    <!-- Token Entry Card -->
    <div class="my-auto space-y-6 py-4">
      <div class="card bg-white p-5 shadow-sm space-y-4">
        <div class="text-center">
          <div class="mx-auto mb-2 flex h-12 w-12 items-center justify-center rounded-full bg-brand-100 text-2xl text-brand-600">
            🔑
          </div>
          <h2 class="text-lg font-bold text-slate-800">Masukkan Token Ujian</h2>
          <p class="text-xs text-slate-500">Minta 6 karakter token kepada pengawas ruang Anda</p>
        </div>

        <form @submit.prevent="joinUjian" class="space-y-4">
          <div>
            <input
              :value="token"
              @input="onTokenInput"
              type="text"
              inputmode="text"
              maxlength="6"
              placeholder="CONTOH: 7K9X2B"
              autocomplete="off"
              autofocus
              required
              class="input text-center font-mono text-2xl font-bold tracking-widest text-slate-900 uppercase !min-h-[52px]"
            />
          </div>

          <p v-if="errorMessage" role="alert" class="text-xs font-semibold text-status-danger text-center">
            ⚠ {{ errorMessage }}
          </p>

          <button
            type="submit"
            :disabled="token.length < 6 || loading"
            class="btn-primary w-full text-base font-bold !min-h-[48px]"
          >
            {{ loading ? 'Memeriksa Token...' : 'Masuk Ruang Ujian' }}
          </button>
        </form>
      </div>

      <!-- Checklist Persiapan Perangkat HP (FR-FE-02, FR-FE-03) -->
      <div class="card bg-slate-50 border border-slate-200 p-4 space-y-2 text-xs">
        <h3 class="font-bold text-slate-700 uppercase tracking-wider text-[11px]">
          📱 Checklist Persiapan HP / Perangkat:
        </h3>
        <label class="flex items-center gap-2 cursor-pointer">
          <input type="checkbox" v-model="checklist.baterai" class="h-4 w-4 rounded text-brand-600" />
          <span class="text-slate-600">Baterai HP minimal 50% atau tersambung charger</span>
        </label>
        <label class="flex items-center gap-2 cursor-pointer">
          <input type="checkbox" v-model="checklist.koneksi" class="h-4 w-4 rounded text-brand-600" />
          <span class="text-slate-600">Koneksi WiFi / Seluler stabil di ruang ujian</span>
        </label>
        <label class="flex items-center gap-2 cursor-pointer">
          <input type="checkbox" v-model="checklist.fokus" class="h-4 w-4 rounded text-brand-600" />
          <span class="text-slate-600">Dilarang berpindah aplikasi selama ujian berlangsung</span>
        </label>
      </div>
    </div>

    <!-- Footer -->
    <footer class="text-center text-[11px] text-slate-400 py-2">
      Sistem Ujian Sekolah Terstandar • CBT v1.0
    </footer>
  </main>
</template>
