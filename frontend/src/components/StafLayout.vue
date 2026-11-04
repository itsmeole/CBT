<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter, useRoute, RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import ConfirmLogoutModal from '@/components/ConfirmLogoutModal.vue'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const role = computed(() => auth.user?.role)
const isOperatorOrSuperadmin = computed(() => role.value === 'superadmin' || role.value === 'operator')
const isGuruOrSuperadmin = computed(() => role.value === 'superadmin' || role.value === 'guru')

// Desktop sidebar collapse state
const isCollapsed = ref(false)

// Mobile drawer open state
const mobileOpen = ref(false)

// Logout confirmation state
const showLogoutConfirm = ref(false)
const isLoggingOut = ref(false)

onMounted(() => {
  const saved = localStorage.getItem('cbt_staf_sidebar_collapsed')
  if (saved !== null) {
    isCollapsed.value = saved === 'true'
  }
})

function toggleCollapse() {
  isCollapsed.value = !isCollapsed.value
  localStorage.setItem('cbt_staf_sidebar_collapsed', String(isCollapsed.value))
}

function requestLogout() {
  mobileOpen.value = false
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

interface NavItem {
  name: string
  to: string
  visible: boolean
  icon: string
}

const navItems = computed<NavItem[]>(() => [
  {
    name: 'Ringkasan',
    to: '/staf',
    visible: true,
    icon: 'dashboard'
  },
  {
    name: 'Master Data',
    to: '/staf/master',
    visible: isOperatorOrSuperadmin.value,
    icon: 'users'
  },
  {
    name: 'Bank Soal',
    to: '/staf/bank-soal',
    visible: isGuruOrSuperadmin.value,
    icon: 'book'
  },
  {
    name: 'Jadwal & Paket Ujian',
    to: '/staf/jadwal',
    visible: isOperatorOrSuperadmin.value || isGuruOrSuperadmin.value,
    icon: 'calendar'
  },
  {
    name: 'Kontrol Sesi Operator',
    to: '/staf/kontrol-sesi',
    visible: isOperatorOrSuperadmin.value,
    icon: 'control'
  }
])

function isActive(path: string) {
  if (path === '/staf') {
    return route.path === '/staf'
  }
  return route.path.startsWith(path)
}
</script>

<template>
  <div class="min-h-[100dvh] bg-slate-50 font-sans text-slate-800 flex flex-col md:flex-row">
    <!-- ======================================================== -->
    <!-- MOBILE TOP BAR (< md)                                    -->
    <!-- ======================================================== -->
    <header class="md:hidden sticky top-0 z-30 flex items-center justify-between border-b border-slate-200 bg-white/95 backdrop-blur px-4 py-3 shadow-xs">
      <div class="flex items-center gap-3">
        <button
          type="button"
          @click="mobileOpen = true"
          class="rounded-xl p-2 text-slate-600 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-brand-500/20 active:scale-95 transition-all"
          aria-label="Buka Menu"
        >
          <!-- Hamburger SVG -->
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-6 w-6">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
          </svg>
        </button>
        <div class="flex items-center gap-2">
          <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-600 text-xs font-black text-white shadow-xs">
            CBT
          </div>
          <span class="text-sm font-bold text-slate-900 tracking-tight">CBT Ujian Sekolah</span>
        </div>
      </div>

      <div class="flex items-center gap-2">
        <span class="rounded-md bg-brand-50 border border-brand-200 px-2 py-0.5 text-[11px] font-bold uppercase text-brand-700">
          {{ role }}
        </span>
        <button
          type="button"
          @click="requestLogout"
          class="rounded-xl p-2 text-slate-500 hover:text-red-600 hover:bg-red-50 focus:outline-none transition-colors"
          title="Keluar Akun"
        >
          <!-- Logout SVG -->
          <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-5 w-5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M12 9l-3 3m0 0 3 3m-3-3h12.75" />
          </svg>
        </button>
      </div>
    </header>

    <!-- ======================================================== -->
    <!-- MOBILE OFF-CANVAS DRAWER (< md)                          -->
    <!-- ======================================================== -->
    <!-- Backdrop Overlay -->
    <transition
      enter-active-class="transition-opacity duration-300 ease-out"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="transition-opacity duration-200 ease-in"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <div
        v-if="mobileOpen"
        class="fixed inset-0 z-40 bg-slate-900/40 backdrop-blur-xs md:hidden"
        @click="mobileOpen = false"
      />
    </transition>

    <!-- Drawer Panel -->
    <transition
      enter-active-class="transition-transform duration-300 ease-out"
      enter-from-class="-translate-x-full"
      enter-to-class="translate-x-0"
      leave-active-class="transition-transform duration-200 ease-in"
      leave-from-class="translate-x-0"
      leave-to-class="-translate-x-full"
    >
      <aside
        v-if="mobileOpen"
        class="fixed inset-y-0 left-0 z-50 w-72 bg-white shadow-2xl flex flex-col justify-between md:hidden border-r border-slate-200"
      >
        <div>
          <!-- Drawer Header -->
          <div class="flex items-center justify-between border-b border-slate-100 p-4">
            <div class="flex items-center gap-2.5">
              <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-brand-600 text-sm font-black text-white shadow">
                CBT
              </div>
              <div>
                <h2 class="text-sm font-bold text-slate-900 leading-tight">CBT Ujian Sekolah</h2>
                <p class="text-[11px] text-slate-500">Panel Manajemen Staf</p>
              </div>
            </div>
            <button
              type="button"
              @click="mobileOpen = false"
              class="rounded-lg p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100"
              aria-label="Tutup Menu"
            >
              <!-- Close X SVG -->
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-5 w-5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
              </svg>
            </button>
          </div>

          <!-- Drawer Navigation -->
          <nav class="p-3 space-y-1">
            <template v-for="item in navItems" :key="item.to">
              <RouterLink
                v-if="item.visible"
                :to="item.to"
                @click="mobileOpen = false"
                class="flex items-center gap-3 rounded-xl px-3.5 py-2.5 text-sm font-medium transition-colors"
                :class="isActive(item.to)
                  ? 'bg-brand-600 text-white font-semibold shadow-xs'
                  : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'"
              >
                <!-- Dashboard Icon -->
                <svg v-if="item.icon === 'dashboard'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-5 w-5 shrink-0">
                  <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                </svg>
                <!-- Users Icon -->
                <svg v-else-if="item.icon === 'users'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-5 w-5 shrink-0">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
                </svg>
                <!-- Book Icon -->
                <svg v-else-if="item.icon === 'book'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-5 w-5 shrink-0">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
                </svg>
                <!-- Calendar Icon -->
                <svg v-else-if="item.icon === 'calendar'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-5 w-5 shrink-0">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
                </svg>
                <!-- Control / Sliders Icon -->
                <svg v-else-if="item.icon === 'control'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-5 w-5 shrink-0">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75" />
                </svg>
                <span>{{ item.name }}</span>
              </RouterLink>
            </template>
          </nav>
        </div>

        <!-- Drawer User Profile Footer -->
        <div class="border-t border-slate-100 p-4">
          <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-2.5">
              <div class="flex h-9 w-9 items-center justify-center rounded-xl bg-slate-100 text-xs font-bold text-slate-700">
                {{ (auth.user?.nama || auth.user?.username || 'U').charAt(0).toUpperCase() }}
              </div>
              <div class="overflow-hidden">
                <p class="text-xs font-semibold text-slate-800 truncate">{{ auth.user?.nama || auth.user?.username }}</p>
                <span class="inline-block rounded bg-brand-100 px-1.5 py-0.2 text-[10px] font-bold uppercase text-brand-700">
                  {{ role }}
                </span>
              </div>
            </div>
          </div>
          <button
            type="button"
            @click="requestLogout"
            class="w-full flex items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white py-2 text-xs font-semibold text-slate-700 hover:bg-red-50 hover:text-red-600 hover:border-red-200 transition-colors"
          >
            <!-- Logout SVG -->
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M12 9l-3 3m0 0 3 3m-3-3h12.75" />
            </svg>
            <span>Keluar Akun</span>
          </button>
        </div>
      </aside>
    </transition>

    <!-- ======================================================== -->
    <!-- DESKTOP COLLAPSIBLE SIDEBAR (>= md)                      -->
    <!-- ======================================================== -->
    <aside
      class="hidden md:flex flex-col justify-between shrink-0 border-r border-slate-200 bg-white sticky top-0 h-[100dvh] transition-all duration-300 ease-in-out z-20"
      :class="isCollapsed ? 'w-20' : 'w-64'"
    >
      <!-- Sidebar Top Section -->
      <div>
        <!-- Logo & Collapse Toggle -->
        <div class="flex items-center border-b border-slate-100 px-4 py-4" :class="isCollapsed ? 'justify-center' : 'justify-between'">
          <!-- Brand -->
          <div v-if="!isCollapsed" class="flex items-center gap-3 overflow-hidden">
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-600 text-sm font-black text-white shadow-xs">
              CBT
            </div>
            <div class="truncate">
              <h1 class="text-sm font-extrabold text-slate-900 leading-tight">CBT Ujian Sekolah</h1>
              <p class="text-[11px] text-slate-400 font-medium">Panel Staf</p>
            </div>
          </div>
          <div v-else class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-600 text-sm font-black text-white shadow-xs">
            CBT
          </div>

          <!-- Collapse Button -->
          <button
            v-if="!isCollapsed"
            type="button"
            @click="toggleCollapse"
            class="rounded-xl p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-colors"
            title="Ciutkan Sidebar"
            aria-label="Ciutkan Sidebar"
          >
            <!-- Chevron Left SVG -->
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-4 w-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" />
            </svg>
          </button>
        </div>

        <!-- If collapsed, show expand button below logo -->
        <div v-if="isCollapsed" class="flex justify-center pt-2">
          <button
            type="button"
            @click="toggleCollapse"
            class="rounded-xl p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-colors"
            title="Perluas Sidebar"
            aria-label="Perluas Sidebar"
          >
            <!-- Chevron Right SVG -->
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-4 w-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
            </svg>
          </button>
        </div>

        <!-- Navigation Menu -->
        <nav class="p-3 space-y-1.5">
          <template v-for="item in navItems" :key="item.to">
            <RouterLink
              v-if="item.visible"
              :to="item.to"
              class="group relative flex items-center rounded-xl text-sm transition-all"
              :class="[
                isCollapsed
                  ? 'justify-center p-3'
                  : 'gap-3 px-3.5 py-2.5',
                isActive(item.to)
                  ? 'bg-brand-600 text-white font-semibold shadow-xs'
                  : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'
              ]"
              :title="isCollapsed ? item.name : undefined"
            >
              <!-- Dashboard Icon -->
              <svg v-if="item.icon === 'dashboard'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-5 w-5 shrink-0">
                <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
              </svg>
              <!-- Users Icon -->
              <svg v-else-if="item.icon === 'users'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-5 w-5 shrink-0">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z" />
              </svg>
              <!-- Book Icon -->
              <svg v-else-if="item.icon === 'book'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-5 w-5 shrink-0">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25" />
              </svg>
              <!-- Calendar Icon -->
              <svg v-else-if="item.icon === 'calendar'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-5 w-5 shrink-0">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
              </svg>
              <!-- Control / Sliders Icon -->
              <svg v-else-if="item.icon === 'control'" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-5 w-5 shrink-0">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75" />
              </svg>

              <!-- Label (Visible only when expanded) -->
              <span v-if="!isCollapsed" class="truncate">{{ item.name }}</span>

              <!-- Floating Tooltip when collapsed -->
              <div
                v-if="isCollapsed"
                class="pointer-events-none absolute left-full ml-2 z-50 hidden rounded-lg bg-slate-900 px-2.5 py-1 text-xs font-medium text-white shadow-lg group-hover:block whitespace-nowrap"
              >
                {{ item.name }}
              </div>
            </RouterLink>
          </template>
        </nav>
      </div>

      <!-- Sidebar Bottom Section / User Profile -->
      <div class="border-t border-slate-100 p-3">
        <!-- Expanded Profile -->
        <div v-if="!isCollapsed" class="space-y-3">
          <div class="flex items-center gap-3 px-1">
            <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-xs font-bold text-slate-700">
              {{ (auth.user?.nama || auth.user?.username || 'U').charAt(0).toUpperCase() }}
            </div>
            <div class="overflow-hidden">
              <p class="text-xs font-semibold text-slate-800 truncate">{{ auth.user?.nama || auth.user?.username }}</p>
              <span class="inline-block rounded bg-brand-100 px-1.5 py-0.2 text-[10px] font-bold uppercase text-brand-700">
                {{ role }}
              </span>
            </div>
          </div>
          <button
            type="button"
            @click="requestLogout"
            class="w-full flex items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white py-2 text-xs font-semibold text-slate-700 hover:bg-red-50 hover:text-red-600 hover:border-red-200 active:scale-[0.99] transition-all"
          >
            <!-- Logout SVG -->
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-4 w-4">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M12 9l-3 3m0 0 3 3m-3-3h12.75" />
            </svg>
            <span>Keluar Akun</span>
          </button>
        </div>

        <!-- Collapsed Profile -->
        <div v-else class="flex flex-col items-center gap-2">
          <div
            class="flex h-9 w-9 items-center justify-center rounded-xl bg-slate-100 text-xs font-bold text-slate-700"
            :title="`${auth.user?.nama || auth.user?.username} (${role})`"
          >
            {{ (auth.user?.nama || auth.user?.username || 'U').charAt(0).toUpperCase() }}
          </div>
          <button
            type="button"
            @click="requestLogout"
            class="rounded-xl p-2 text-slate-400 hover:text-red-600 hover:bg-red-50 transition-colors"
            title="Keluar Akun"
          >
            <!-- Logout SVG -->
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="h-5 w-5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M12 9l-3 3m0 0 3 3m-3-3h12.75" />
            </svg>
          </button>
        </div>
      </div>
    </aside>

    <!-- ======================================================== -->
    <!-- MAIN CONTENT AREA                                        -->
    <!-- ======================================================== -->
    <div class="flex-1 min-w-0 flex flex-col">
      <main class="flex-1 p-4 sm:p-6 lg:p-8 max-w-7xl w-full mx-auto">
        <slot />
      </main>
    </div>

    <!-- Confirm Logout Dialog -->
    <ConfirmLogoutModal
      v-model="showLogoutConfirm"
      :loading="isLoggingOut"
      message="Apakah Anda yakin ingin keluar dari panel staf CBT?"
      @confirm="handleConfirmedLogout"
    />
  </div>
</template>

