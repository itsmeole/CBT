import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { apiClient, setAuthToken, setUnauthenticatedHandler } from '@/api/client'
import { bacaTokenTab, hapusTokenTab, simpanTokenTab } from '@/lib/sesiTab'
import type { AuthUser, Role } from '@/types/auth'

interface AuthResponse {
  token?: string
  user?: AuthUser
  data?: AuthUser
}

export const useAuthStore = defineStore('auth', () => {
  const user = ref<AuthUser | null>(null)
  /** true setelah pengecekan sesi awal (GET /me) selesai, berhasil maupun tidak. */
  const initialized = ref(false)

  const isAuthenticated = computed(() => user.value !== null)
  const role = computed<Role | null>(() => user.value?.role ?? null)

  async function fetchMe(): Promise<void> {
    // Setelah refresh, token dipulihkan dari penyimpanan tab (lihat lib/sesiTab).
    const tersimpan = bacaTokenTab()
    if (tersimpan) setAuthToken(tersimpan)
    try {
      const { data } = await apiClient.get<AuthResponse>('/me')
      user.value = data.user ?? data.data ?? null
    } catch {
      user.value = null
    } finally {
      initialized.value = true
    }
  }

  async function login(username: string, password: string): Promise<void> {
    const { data } = await apiClient.post<AuthResponse>('/auth/login', { username, password })
    if (data.token) {
      setAuthToken(data.token)
    }
    user.value = data.user ?? data.data ?? null
    if (data.token) simpanTokenTab(data.token)
    initialized.value = true
  }

  async function logout(): Promise<void> {
    try {
      await apiClient.post('/auth/logout')
    } finally {
      clear()
    }
  }

  function setUser(data: AuthUser): void {
    user.value = data
  }

  function clear(): void {
    user.value = null
    setAuthToken(null)
    hapusTokenTab()
  }

  // Jika server menjawab 401 di mana pun, sesi lokal langsung dibersihkan.
  setUnauthenticatedHandler(clear)

  return { user, initialized, isAuthenticated, role, fetchMe, login, logout, setUser, clear }
})

