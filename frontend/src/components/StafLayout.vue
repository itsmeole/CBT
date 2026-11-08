<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import ConfirmLogoutModal from '@/components/ConfirmLogoutModal.vue'
import StafNavList from '@/components/StafNavList.vue'
import { buatMenu } from '@/lib/navigasi'

const auth = useAuthStore()
const router = useRouter()

const role = computed(() => auth.user?.role)

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

const navItems = computed(() => buatMenu(role.value ?? null))
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
          <img src="/logo.webp" alt="ICBT" class="h-8 w-8 shrink-0 object-contain rounded-lg" />
          <span class="text-sm font-bold text-slate-900 tracking-tight">ICBT</span>
        </div>
      </div>

      <div class="flex items-center gap-2">
        <span class="rounded-md bg-brand-50 border border-brand-200 px-2 py-0.5 text-[11px] font-bold capitalize text-brand-700">
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
              <img src="/logo.webp" alt="ICBT" class="h-9 w-9 shrink-0 object-contain rounded-xl" />
              <div>
                <h2 class="text-sm font-bold text-slate-900 leading-tight">ICBT</h2>
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
          <StafNavList :items="navItems" :collapsed="false" @navigate="mobileOpen = false" />
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
                <span class="inline-block rounded bg-brand-100 px-1.5 py-0.2 text-[10px] font-bold capitalize text-brand-700">
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
            <img src="/logo.webp" alt="ICBT" class="h-10 w-10 shrink-0 object-contain rounded-xl" />
            <div class="truncate">
              <h1 class="text-sm font-extrabold text-slate-900 leading-tight">ICBT</h1>
              <p class="text-[11px] text-slate-400 font-medium">Panel Staf</p>
            </div>
          </div>
          <!-- Saat diciutkan: logo = tombol perluas. Hover/fokus -> logo memudar, panah kanan muncul. -->
          <button
            v-else
            type="button"
            @click="toggleCollapse"
            class="group relative flex h-10 w-10 items-center justify-center rounded-xl focus-visible:outline-2 focus-visible:outline-brand-600"
            title="Perluas Sidebar"
            aria-label="Perluas Sidebar"
          >
            <img
              src="/logo.webp"
              alt=""
              class="absolute h-10 w-10 object-contain rounded-xl transition-opacity duration-300 group-hover:opacity-0 group-focus-visible:opacity-0"
            />
            <span
              class="absolute inset-0 flex items-center justify-center rounded-xl bg-slate-100 text-slate-600 opacity-0 transition-opacity duration-300 group-hover:opacity-100 group-focus-visible:opacity-100"
              aria-hidden="true"
            >
              <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="h-5 w-5">
                <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5" />
              </svg>
            </span>
          </button>

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

        <!-- Navigation Menu -->
        <StafNavList :items="navItems" :collapsed="isCollapsed" />
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
              <span class="inline-block rounded bg-brand-100 px-1.5 py-0.2 text-[10px] font-bold capitalize text-brand-700">
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
      message="Apakah Anda yakin ingin keluar dari panel staf ICBT?"
      @confirm="handleConfirmedLogout"
    />
  </div>
</template>

