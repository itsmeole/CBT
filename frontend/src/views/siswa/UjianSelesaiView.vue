<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import ConfirmLogoutModal from '@/components/ConfirmLogoutModal.vue'

const auth = useAuthStore()
const router = useRouter()

const showLogoutConfirm = ref(false)
const isLoggingOut = ref(false)

function requestLogout() {
  showLogoutConfirm.value = true
}

async function handleConfirmedLogout() {
  isLoggingOut.value = true
  try {
    await auth.logout()
    showLogoutConfirm.value = false
    router.replace({ name: 'login' })
  } finally {
    isLoggingOut.value = false
  }
}

function handleKembali() {
  router.replace({ name: 'siswa-beranda' })
}
</script>

<template>
  <main class="mx-auto flex min-h-dvh max-w-md flex-col justify-between p-4 sm:p-6 text-center">
    <!-- Header -->
    <header class="border-b border-slate-200 pb-3 flex items-center justify-center gap-2">
      <img src="/favicon.svg" alt="ICBT" class="h-6 w-6 shrink-0 object-contain rounded-md" />
      <h1 class="text-lg font-bold text-slate-800">ICBT</h1>
    </header>

    <!-- Main Card -->
    <div class="my-auto space-y-6 py-6">
      <div class="card bg-white p-6 shadow-sm border border-slate-200 space-y-4">
        <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-600 shadow-xs">
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-8 w-8">
            <path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" />
          </svg>
        </div>

        <div>
          <h2 class="text-xl font-bold text-slate-800">Ujian Telah Selesai!</h2>
          <p class="mt-2 text-sm text-slate-600 leading-relaxed">
            Terima kasih telah mengikuti ujian dengan jujur dan tertib. Seluruh berkas jawaban Anda telah berhasil direkam secara permanen ke server.
          </p>
        </div>

        <!-- Strict no-score rule notice -->
        <div class="rounded-lg bg-slate-50 border border-slate-200 p-3 text-xs text-slate-500">
          Hasil evaluasi dan penilaian akan diumumkan secara resmi oleh pihak sekolah setelah seluruh rangkaian ujian berakhir.
        </div>

        <div class="pt-2 flex flex-col gap-2">
          <button @click="handleKembali" class="btn-primary w-full text-xs font-bold">
            Kembali ke Beranda Siswa
          </button>
          <button @click="requestLogout" class="btn-secondary w-full text-xs">
            Keluar Akun
          </button>
        </div>
      </div>
    </div>

    <!-- Footer -->
    <footer class="py-2 text-xs text-slate-400">
      CBT Sekolah Terstandar • Nilai Dilindungi & Terjamin
    </footer>

    <!-- Confirm Logout Dialog -->
    <ConfirmLogoutModal
      v-model="showLogoutConfirm"
      :loading="isLoggingOut"
      message="Apakah Anda yakin ingin keluar dari akun ujian?"
      @confirm="handleConfirmedLogout"
    />
  </main>
</template>

