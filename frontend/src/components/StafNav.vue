<script setup lang="ts">
import { computed } from 'vue'
import { useRouter, useRoute, RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const role = computed(() => auth.user?.role)

async function handleLogout() {
  await auth.logout()
  router.replace({ name: 'login' })
}

const isOperatorOrSuperadmin = computed(() => role.value === 'superadmin' || role.value === 'operator')
const isGuruOrSuperadmin = computed(() => role.value === 'superadmin' || role.value === 'guru')
</script>

<template>
  <header class="border-b border-slate-200 bg-white">
    <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-3">
      <div class="flex items-center gap-3">
        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-brand-600 font-bold text-white shadow">
          CBT
        </div>
        <div>
          <h1 class="text-base font-bold leading-tight text-slate-800">CBT Ujian Sekolah</h1>
          <p class="text-xs text-slate-500">Panel Manajemen Staf</p>
        </div>
      </div>

      <div class="flex items-center gap-3">
        <div class="hidden text-right sm:block">
          <p class="text-sm font-semibold text-slate-800">{{ auth.user?.nama || auth.user?.username }}</p>
          <span class="inline-block rounded bg-brand-100 px-2 py-0.5 text-xs font-semibold uppercase text-brand-700">
            {{ role }}
          </span>
        </div>
        <button @click="handleLogout" class="btn-secondary !min-h-0 !min-w-0 !px-3 !py-1.5 text-xs">
          Keluar
        </button>
      </div>
    </div>

    <!-- Navigation Bar -->
    <nav class="border-t border-slate-100 bg-slate-50 px-4">
      <div class="mx-auto flex max-w-7xl gap-2 overflow-x-auto py-2 text-sm font-medium">
        <RouterLink
          to="/staf"
          class="rounded-lg px-3 py-1.5 transition-colors"
          :class="route.path === '/staf' ? 'bg-brand-600 text-white font-semibold' : 'text-slate-600 hover:bg-slate-200'"
        >
          Ringkasan
        </RouterLink>

        <RouterLink
          v-if="isOperatorOrSuperadmin"
          to="/staf/master"
          class="rounded-lg px-3 py-1.5 transition-colors"
          :class="route.path === '/staf/master' ? 'bg-brand-600 text-white font-semibold' : 'text-slate-600 hover:bg-slate-200'"
        >
          Master Data & Excel
        </RouterLink>

        <RouterLink
          v-if="isGuruOrSuperadmin"
          to="/staf/bank-soal"
          class="rounded-lg px-3 py-1.5 transition-colors"
          :class="route.path === '/staf/bank-soal' ? 'bg-brand-600 text-white font-semibold' : 'text-slate-600 hover:bg-slate-200'"
        >
          Bank Soal & Word
        </RouterLink>

        <RouterLink
          v-if="isOperatorOrSuperadmin || isGuruOrSuperadmin"
          to="/staf/jadwal"
          class="rounded-lg px-3 py-1.5 transition-colors"
          :class="route.path === '/staf/jadwal' ? 'bg-brand-600 text-white font-semibold' : 'text-slate-600 hover:bg-slate-200'"
        >
          Jadwal & Paket Ujian
        </RouterLink>

        <RouterLink
          v-if="isOperatorOrSuperadmin"
          to="/staf/kontrol-sesi"
          class="rounded-lg px-3 py-1.5 transition-colors"
          :class="route.path === '/staf/kontrol-sesi' ? 'bg-brand-600 text-white font-semibold' : 'text-slate-600 hover:bg-slate-200'"
        >
          ⚡ Kontrol Sesi & Mulai
        </RouterLink>
      </div>
    </nav>
  </header>
</template>
