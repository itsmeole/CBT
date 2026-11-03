<script setup lang="ts">
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

async function handleLogout() {
  await auth.logout()
  router.replace({ name: 'login' })
}

function handleKembali() {
  router.replace({ name: 'siswa-beranda' })
}
</script>

<template>
  <main class="mx-auto flex min-h-dvh max-w-md flex-col justify-between p-4 sm:p-6 text-center">
    <!-- Header -->
    <header class="border-b border-slate-200 pb-3">
      <h1 class="text-lg font-bold text-slate-800">CBT Ujian Sekolah</h1>
      <p class="text-xs text-slate-500">Status Pengerjaan Ujian</p>
    </header>

    <!-- Main Card -->
    <div class="my-auto space-y-6 py-6">
      <div class="card bg-white p-6 shadow-sm border border-slate-200 space-y-4">
        <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-emerald-100 text-3xl text-emerald-600">
          ✓
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
          <button @click="handleLogout" class="btn-secondary w-full text-xs">
            Keluar Akun
          </button>
        </div>
      </div>
    </div>

    <!-- Footer -->
    <footer class="py-2 text-xs text-slate-400">
      CBT Sekolah Terstandar • Nilai Dilindungi & Terjamin
    </footer>
  </main>
</template>

